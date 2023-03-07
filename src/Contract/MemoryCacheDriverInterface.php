<?php

namespace Aston\MemoryCache\Contract;

use Hyperf\Contract\PackerInterface;

interface MemoryCacheDriverInterface
{
    /**
     * Notes: 获取缓存中的key数量
     * User: 陈朋
     * DateTime: 2023/3/7 17:16
     * @return int
     */
    public function dbCount(): int;

    /**
     * Notes: 获取缓存中的键值对
     * User: 陈朋
     * DateTime: 2023/3/7 17:16
     * @return array
     */
    public function dbData(): array;

    public function get(string $key): ?string;

    public function set(string $key, string $value): bool;

    public function del(...$keys): int;

    public function hGet(string $key, string $field): ?string;

    public function hLen(string $key): ?int;

    public function hMGet(string $key, array $fields): ?array;

    public function hGetAll(string $key): ?array;

    public function hExists(string $key, string $field): ?bool;

    public function hKeys(string $key): ?array;

    public function hVals(string $key): ?array;

    public function packer(): PackerInterface;
}