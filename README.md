# hyperf memory cache

基于 Swoole Hyperf 框架，将 SwooleTable 作为 Redis 的上级缓存

## 安装

使用 composer

```
composer require aston/memory-cache
```

发布配置文件

```
php bin/hyperf.php vendor:publish aston/memory-cache
```

## 配置文件说明

```php
[
    'default' => [
        'driver' => SwooleTableDriver::class,
        'packer' => PhpSerializerPacker::class,
        'tables' => [
            'cache' => [
                //内存表最大行数
                'table_size' => 1024,
                'column_value' => [
                    'type' => Table::TYPE_STRING,//内存表缓存值的字段类型
                    'size' => 1024//缓存值最大存储长度 设置后，设置的字符串不能超过 size 指定的最大长度
                ],
                //拦截读取缓存的redis命令
                'commands' => [
                    'get', 'hGet', 'hGetAll', 'hMGet', 'hLen' //目前最多支持这么多，可指定拦截命令，留空代表不拦截，
                ]
            ],
        ],
    ],
]
```

### 实现原理：
通过 AOP 拦截 Redis 的读操作（目前只实现了string、hash类型）

创建自定义进程订阅 Redis 键事件，通过事件维护Swoole Table中的数据过期与删除


### 使用
在 Hyperf 中正常调用 Redis 即可，开发中无需关心数据是从内存表中获取还是从 Redis 获取到的。
如果在 config.php 开启了 DEBUG 等级的日志，开发时会打印对应日志。

![hyperf框架中基于swoole table实现的redis上级缓存](https://cdn.learnku.com/uploads/images/202303/07/100058/xXINve4AA3.png!large)


![hyperf框架中基于swoole table实现的redis上级缓存](https://cdn.learnku.com/uploads/images/202303/07/100058/d5owOmgcEr.png!large)

