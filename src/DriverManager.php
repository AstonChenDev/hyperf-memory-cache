<?php

namespace Aston\MemoryCache;

use Aston\MemoryCache\Contract\MemoryCacheDriverInterface;
use Aston\MemoryCache\Implement\SwooleTableDriver;
use Exception;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Utils\ApplicationContext;

class DriverManager
{
    /**
     * @var ConfigInterface
     */
    protected ConfigInterface $config;

    protected array $drivers = [];

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @throws Exception
     */
    public function getDriver($name = 'default'): MemoryCacheDriverInterface
    {
        if (isset($this->drivers[$name]) && $this->drivers[$name] instanceof MemoryCacheDriverInterface) {
            return $this->drivers[$name];
        }

        $config = $this->config->get("memory_cache.{$name}");
        if (empty($config)) {
            throw new Exception(sprintf('The cache config %s is invalid.', $name));
        }

        $driverClass = $config['driver'] ?? SwooleTableDriver::class;

        $driver = make($driverClass, ['config' => $config]);

        return $this->drivers[$name] = $driver;
    }

    public static function analyze():array
    {
        $hit_count =  ApplicationContext::getContainer()->get('hit_counter')->get();
        $miss_count =  ApplicationContext::getContainer()->get('miss_counter')->get();
        return [
            'hit' => $hit_count,
            'miss' => $miss_count,
        ];
    }
}
