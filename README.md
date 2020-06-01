Container
=============
A simple Container is implemented based on psr-11

Install
-------
To install with composer
```sh
composer require john-jun/container
```

Test
-----
```sh
composer test
```

Usage
-----
```php
$container = new \Air\Container\Container();
$redis = new \Redis();

$container->singleton('obj', $redis);
$container->singleton('redis', \Redis::class);
$container->bind('name', function(\Redis $redis) {
    return $redis;
}, true);

$container->get('obj');
$container->make('redis');
$container->make('name');
$container->make('obj name more');

$container->has('obj');
$container->alias('objAlias', 'obj');
```