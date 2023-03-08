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
                 'enable' => true,//上级缓存开关, false 则完全不走上级缓存
                //内存表最大行数 根据机器内存配置 越大越好
                'table_size' => 1024,
                'column_value' => [
                    'type' => Table::TYPE_STRING,//内存表缓存值的字段类型
                    'size' => 1024//缓存值最大存储长度，缓存不能超过 size 指定的最大长度 否则不做缓存 避免数据不符合预期 根据实际情况配置
                ],
                //拦截读取缓存的redis命令
                'commands' => [
                    'get', 'hGet', 'hGetAll', 'hMGet', 'hLen','hexists','hkeys','hvals' //目前最多支持这么多，可指定拦截命令，留空代表不拦截，
                ]
            ],
        ],
    ],
]
```

### 实现原理：
通过 AOP 拦截 Redis 的读操作（目前只实现了string、hash类型）

```php
#[Aspect]
class CacheAspect extends AbstractAspect
{
    public $classes = [Redis::class];

    private const ALLOWED_METHODS = [
        'get', 'hget', 'hgetall', 'hmget', 'hlen', 'hexists', 'hkeys', 'hvals'
    ];
	...

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        ...
        
        if (($from_cache = $this->$method(...$arguments)) === null) {
            $this->container->get('miss_counter')->add();
            $this->logger->debug('内存表获取缓存失败， 回源获取: method: ' . $method . '; args: ' . json_encode($arguments));
            $from_redis = $proceedingJoinPoint->process();
            //此处将获取到的数据缓存到内存表
            $this->storeToCache($method, $from_redis, $arguments);
            return $from_redis;
        }
        ...
        
        $this->container->get('hit_counter')->add();
        return $from_cache;
    }

```

创建自定义进程订阅 Redis 键事件，通过事件维护Swoole Table中的数据过期与删除

```php
class RedisEventProcess extends AbstractProcess
{
    public function __construct() {...}

    public function handle(): void
    {
        $pool = $this->container->get(ConfigInterface::class)->get('redis.pool') ?? 'default';
        $db = $this->container->get(ConfigInterface::class)->get("redis.$pool.db") ?? 0;
        $this->redis = $this->container->get(RedisFactory::class)->get($pool);
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        $this->redis->config('SET', 'notify-keyspace-events', 'KEA'); // 设置参数
        $this->redis->psubscribe([
            "__keyevent@{$db}__:del",
            "__keyevent@{$db}__:expired",
            "__keyevent@{$db}__:hdel",
            "__keyevent@{$db}__:hset",
            "__keyevent@{$db}__:hincrby",
            "__keyevent@{$db}__:hincrbyfloat",
            "__keyevent@{$db}__:set",
            "__keyevent@{$db}__:setrange",
            "__keyevent@{$db}__:append",
            "__keyevent@{$db}__:incrby",
            "__keyevent@{$db}__:incrbyfloat",
            "__keyevent@{$db}__:rename_from",
            "__keyevent@{$db}__:rename_to",
        ], [$this, 'onPublish']);
    }

    public function onPublish($redis, string $pattern, string $event, string $key)
    {
        $arr = explode(':', $event);
        $method = end($arr);
        $this->logger->debug("触发redis键事件, event: $event, , method: $method, key: $key");
        switch (Str::lower($method)) {
            case 'set':
            case 'append':
            case 'incrby':
            case 'incrbyfloat':
                $latest = $this->redis->rawCommand('get', $key);
                if (!$latest) {
                    return;
                }
                $this->logger->debug("刷新string内存缓存 key: $key");
                $this->driver->set($key, $latest);
                break;
            case 'hset':
            case 'hdel':
            case 'hincrby':
            case 'hincrbyfloat':
                $latest = $this->redis->rawCommand('hGetAll', $key);
                if (!$latest) {
                    return;
                }
                $hash = array();
                for ($i = 0; $i < count($latest); $i += 2) {
                    $hash[$latest[$i]] = $latest[$i + 1];
                }
                $this->logger->debug("刷新hash内存缓存 key: $key");
                $this->driver->set($key, $this->driver->packer()->pack($hash));
                break;
            default:
                $this->logger->debug("删除内存缓存 key: $key");
                $this->driver->del($key);
                break;
        }
    }
    ...
}
```

### 使用
在 Hyperf 中正常调用 Redis 即可，开发中无需关心数据是从内存表中获取还是从 Redis 获取到的。
如果在 config.php 开启了 DEBUG 等级的日志，开发时会打印对应日志。

![hyperf框架中基于swoole table实现的redis上级缓存](https://cdn.learnku.com/uploads/images/202303/07/100058/xXINve4AA3.png!large)


![hyperf框架中基于swoole table实现的redis上级缓存](https://cdn.learnku.com/uploads/images/202303/07/100058/d5owOmgcEr.png!large)

### 分析缓存

```
通过调用 Aston\MemoryCache\DriverManager::analyze() 可以返回缓存的命中次数和miss次数
MemoryCacheDriverInterface::dbCount(); //获取缓存中的key数量
MemoryCacheDriverInterface::dbData(); //获取缓存中的键值对
```
```json
{
    "analyze": {
        "hit": 12020,
        "miss": 8292,
        "hit_rate": "59.17%"
    },
    "count": 2151,
    "data": {}
}
```