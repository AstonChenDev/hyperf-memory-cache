<?php

namespace Aston\MemoryCache\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Psr\Container\ContainerInterface;
use Swoole\Atomic;

class AppBootingListeners implements ListenerInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function listen(): array
    {
        return [
            BootApplication::class
        ];
    }

    public function process(object $event)
    {
        $hit_counter = new Atomic();
        $miss_counter = new Atomic();
        $this->container->set('hit_counter', $hit_counter);
        $this->container->set('miss_counter', $miss_counter);
    }
}