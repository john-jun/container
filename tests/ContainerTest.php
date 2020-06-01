<?php
namespace Air\Container\Test;

use Air\Container\Container;
use PHPUnit\Framework\TestCase;
use Redis;

class ContainerTest extends TestCase
{
    public function testSingleton()
    {
        $container = new Container();
        $container->singleton('redis', Redis::class);
        $container->bind('array', function (Redis $redis) {
            return $redis;
        });

        //print_r($container->getBindings());

        var_dump($container->make('redis'));
        var_dump($container->make('redis'));
        //var_dump($container->make('redis'));

        var_dump($container->make('array'));
        var_dump($container->make('array'));
        var_dump($container->make('abc'));
        //var_dump($container->make('redis'));
//        var_dump($container->make('redis'));
        //print_r($container->getBindings());
    }
}
