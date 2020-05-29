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

        $container->singleton('redis', function () {
            return 1.1;
        });

        var_dump($container->make('redis'));
        var_dump($container->make('redis'));

        //var_dump($container->getBindings());
    }
}
