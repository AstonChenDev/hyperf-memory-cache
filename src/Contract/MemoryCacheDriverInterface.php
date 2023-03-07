<?php

namespace Aston\MemoryCache\Contract;

use Hyperf\Contract\PackerInterface;

interface MemoryCacheDriverInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): bool;

    public function del(...$keys): int;

    public function hGet(string $key, string $field): ?string;

    public function hLen(string $key): ?int;

    public function hMGet(string $key, array $fields): ?array;

    public function hGetAll(string $key): ?array;

    public function packer(): PackerInterface;
}