<?php

namespace Aston\MemoryCache\Implement;

use Aston\MemoryCache\Contract\MemoryCacheDriverInterface;
use Hyperf\Contract\PackerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Utils\Packer\PhpSerializerPacker;
use Psr\Container\ContainerInterface;
use Swoole\Table;

class SwooleTableDriver implements MemoryCacheDriverInterface
{
    private PackerInterface $packer;

    private Table $table;

    const CACHE_VALUE_COLUMN = 'v';

    private ContainerInterface $container;

    /**
     * @var int
     */
    private int $value_size;

    public function __construct(ContainerInterface $container, array $config)
    {
        $this->container = $container;
        $this->packer = $container->get($config['packer'] ?? PhpSerializerPacker::class);
        $this->value_size = $config['tables']['cache']['column_value']['size'] ?? 1024;
        $this->table = new Table($config['tables']['cache']['table_size'] ?? 1024);
        $this->table->column(
            self::CACHE_VALUE_COLUMN,
            $config['tables']['cache']['column_value']['type'] ?? Table::TYPE_STRING,
            $this->value_size
        );
        $this->table->create();

    }

    public function get(string $key): ?string
    {
        $cache = $this->table->get($key);
        if ($cache === false) {
            return null;
        }
        return $this->packer->unpack($cache[self::CACHE_VALUE_COLUMN]);
    }

    public function set(string $key, string $value): bool
    {
        try {
            $pack_data = $this->packer->pack($value);
            if (strlen($pack_data) > $this->value_size) {
                throw new \Exception("Swoole\Table::set(): failed to set($key) with $value, the value length is too long");
            }
            return $this->table->set($key, [self::CACHE_VALUE_COLUMN => $pack_data]);
        } catch (\Throwable $throwable) {
            $this->container->get(StdoutLoggerInterface::class)->debug($throwable->getMessage());
            return false;
        }
    }

    public function del(...$keys): int
    {
        $success = 0;
        foreach ($keys as $key) {
            if ($this->table->del($key)) {
                $success++;
            }
        }
        return $success;
    }

    public function hGet(string $key, string $field): ?string
    {
        return $this->hGetAll($key)[$field] ?? null;
    }

    public function hLen(string $key): ?int
    {
        if (is_null($from_cache = $this->hGetAll($key))) {
            return null;
        }
        return count($from_cache);
    }

    public function hMGet(string $key, array $fields): ?array
    {
        if (is_null($from_cache = $this->hGetAll($key))) {
            return null;
        }
        $ret = [];
        foreach ($fields as $field) {
            $ret[$field] = $from_cache[$field] ?? false;
        }
        return $ret;
    }

    public function hGetAll(string $key): ?array
    {
        if (is_null($from_cache = $this->get($key))) {
            return null;
        }
        return $this->packer->unpack($from_cache);
    }

    /**
     * @return PackerInterface
     */
    public function packer(): PackerInterface
    {
        return $this->packer;
    }

    public function hExists(string $key, string $field): ?bool
    {
        if (is_null($this->hGet($key, $field))) {
            return null;
        }
        return true;
    }

    public function hKeys(string $key): ?array
    {
        if (is_null($from_cache = $this->hGetAll($key))) {
            return null;
        }
        return array_keys($from_cache);
    }

    public function hVals(string $key): ?array
    {
        if (is_null($from_cache = $this->hGetAll($key))) {
            return null;
        }
        return array_values($from_cache);
    }

    public function dbCount(): int
    {
        return $this->table->count();
    }

    public function dbData(): array
    {
        $data = [];
        foreach ($this->table as $k => $v) {
            $data[$k] = $this->packer->unpack($v[self::CACHE_VALUE_COLUMN]);
        }
        return $data;
    }
}