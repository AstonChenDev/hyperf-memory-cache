<?php

declare(strict_types=1);

/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */


use Aston\MemoryCache\Implement\SwooleTableDriver;
use Hyperf\Utils\Packer\PhpSerializerPacker;
use Swoole\Table;

return [
    'default' => [
        'driver' => SwooleTableDriver::class,
        'packer' => PhpSerializerPacker::class,
        'tables' => [
            'cache' => [
                'enable' => true,//上级缓存开关
                //内存表最大行数 根据机器内存配置 越大越好
                'table_size' => 1024,
                'column_value' => [
                    'type' => Table::TYPE_STRING,//内存表缓存值的字段类型
                    'size' => 1024//缓存值最大存储长度，缓存不能超过 size 指定的最大长度 否则自动截断 返回数据不符合预期 根据实际情况配置
                ],
                //拦截读取缓存的redis命令
                'commands' => [
                    'get', 'hGet', 'hGetAll', 'hMGet', 'hLen','hexists','hkeys','hvals' //目前最多支持这么多，可指定拦截命令，留空代表不拦截，
                ]
            ],
        ],
    ],
];