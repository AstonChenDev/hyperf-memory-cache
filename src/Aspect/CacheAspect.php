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
        'get', 'hget', 'hgetall', 'hmget', 'hlen'
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
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
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
            $this->logger->debug('内存表获取缓存失败， 回源获取: method: ' . $method . '; args: ' . json_encode($arguments));
            return $proceedingJoinPoint->process();
        }
        return $from_cache;
    }

    public function __call($name, $arguments)
    {
        $res = $this->driver->$name(...$arguments);
        $this->logger->debug("调用内存表方法: $name , 参数: => " . json_encode($arguments) . ", 结果 => " . json_encode($res));
        return $res;
    }

    private function setCustomCommands(array $custom_commands)
    {
        foreach ($custom_commands as &$custom_command) {
            $custom_command = Str::lower($custom_command);
        }
        self::$custom_methods = array_intersect(self::ALLOWED_METHODS, $custom_commands);
    }
}
