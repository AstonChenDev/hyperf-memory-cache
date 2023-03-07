<?php

namespace Aston\MemoryCache\Process;

use Aston\MemoryCache\Contract\MemoryCacheDriverInterface;
use Aston\MemoryCache\DriverManager;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;


/**
 * @Process(name="redis-event-process")
 */
class RedisEventProcess extends AbstractProcess
{
    private RedisProxy $redis;

    private StdoutLoggerInterface $logger;

    private MemoryCacheDriverInterface $driver;

    public function __construct(ContainerInterface $container, DriverManager $manager, StdoutLoggerInterface $logger)
    {
        parent::__construct($container);

        $this->container = $container;
        $this->driver = $manager->getDriver();
        $this->logger = $logger;
    }

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
            "__keyevent@{$db}__:set",
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
                $latest = $this->redis->rawCommand('get', $key);
                if (!$latest) {
                    return;
                }
                $this->logger->debug("刷新string内存缓存 key: $key");
                $this->driver->set($key, $latest);
                break;
            case 'hset':
            case 'hdel':
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
}