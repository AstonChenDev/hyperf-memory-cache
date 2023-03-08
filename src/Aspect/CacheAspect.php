<?php

namespace Aston\MemoryCache\Aspect;


use Aston\MemoryCache\Contract\MemoryCacheDriverInterface;
use Aston\MemoryCache\DriverManager;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Redis\Redis;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * @Aspect
 */
#[Aspect]
class CacheAspect extends AbstractAspect
{
    public $classes = [Redis::class];

    private const ALLOWED_METHODS = [
        'get', 'hget', 'hgetall', 'hmget', 'hlen', 'hexists', 'hkeys', 'hvals'
    ];

    private static array $custom_methods = [];

    protected ContainerInterface $container;

    private MemoryCacheDriverInterface $driver;

    private StdoutLoggerInterface $logger;
    private ConfigInterface $config;

    public function __construct(ContainerInterface $container, DriverManager $manager, StdoutLoggerInterface $logger, ConfigInterface $config)
    {
        $this->container = $container;
        $this->driver = $manager->getDriver();
        $this->logger = $logger;
        $this->config = $config;
        $custom_commands = $this->config->get("memory_cache.default.tables.cache.commands", []);
        $this->setCustomCommands($custom_commands);
        if (!$this->checkEnable()) {
            self::$custom_methods = [];
        }
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        if (!self::$custom_methods) {
            return $proceedingJoinPoint->process();
        }
        if (count($proceedingJoinPoint->getArguments()) !== 2) {
            return $proceedingJoinPoint->process();
        }
        [$method, $arguments] = $proceedingJoinPoint->getArguments();
        $method = Str::lower($method);
        if (!in_array($method, self::$custom_methods)) {
            return $proceedingJoinPoint->process();
        }

        if ($method === 'set' && count($arguments) > 2) {
            return $proceedingJoinPoint->process();
        }
        if (($from_cache = $this->$method(...$arguments)) === null) {
            $this->container->get('miss_counter')->add();
            $this->logger->debug('内存表获取缓存失败， 回源获取: method: ' . $method . '; args: ' . json_encode($arguments));
            $from_redis = $proceedingJoinPoint->process();
            $this->storeToCache($method, $from_redis, $arguments);
            return $from_redis;
        }
        $this->container->get('hit_counter')->add();
        return $from_cache;
    }

    public function __call($name, $arguments)
    {
        $res = $this->driver->$name(...$arguments);
        $this->logger->debug("调用内存表方法: $name , 参数: => " . json_encode($arguments) . ", 结果 => " . json_encode($res));
        return $res;
    }

    private function storeToCache(string $method, $from_redis, array $arguments)
    {
        if (!$from_redis) {
            return;
        }
        $key = $arguments[0];
        switch ($method) {
            case 'get':
                $this->logger->debug("回源后刷新 string 缓存 key: $key, value: ". json_encode($from_redis));
                $this->driver->set($key, $from_redis);
                break;
            case 'hgetall':
                $this->logger->debug("回源后刷新 hash 缓存 key: $key, value: ". json_encode($from_redis));
                $this->driver->set($key, $this->driver->packer()->pack($from_redis));
                break;
        }
    }

    private function setCustomCommands(array $custom_commands)
    {
        foreach ($custom_commands as &$custom_command) {
            $custom_command = Str::lower($custom_command);
        }
        self::$custom_methods = array_intersect(self::ALLOWED_METHODS, $custom_commands);
    }

    private function checkEnable(): bool
    {
        return (bool)$this->config->get('memory_cache.default.tables.cache.enable', true);
    }
}
