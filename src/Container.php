<?php
declare(strict_types=1);
namespace Air\Container;

use Air\Container\Exception\ContainerException;
use Air\Container\Exception\NotFoundException;
use Closure;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Class Container
 * @package Air\Container
 */
class Container implements ContainerInterface
{
    /**
     * @var static
     */
    private static $instance;

    /**
     * @var ContainerInterface
     */
    private $parent;

    /**
     * @var array
     */
    private $bindings = [];

    /**
     * @var array
     */
    private $aliases = [];

    /**
     * Container constructor.
     * @param ContainerInterface|null $container
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->parent = $container;

        $this->alias('di', static::class);
        $this->alias('ioc', 'di');
        $this->alias('container', 'di');
        $this->bind(static::class, $this, true);
    }

    /**
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static(...func_get_args());
        }

        return static::$instance;
    }

    /**
     * @param string $id
     * @return mixed|object|null
     * @throws Exception
     */
    public function get($id)
    {
        return $this->make($id);
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return isset($this->bindings[$this->getAlias($id)]);
    }

    /**
     * Set Alias For binding
     * @param $alias
     * @param $abstract
     * @return $this
     */
    public function alias($alias, $abstract)
    {
        $this->aliases[$alias] = $abstract;

        return $this;
    }

    /**
     * @param $abstract
     * @return mixed
     */
    public function getAlias($abstract)
    {
        if (!isset($this->aliases[$abstract])) {
            return $abstract;
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * @param $delAbstract
     */
    public function removeAlias($delAbstract)
    {
        foreach ($this->aliases as $alias => $abstract) {
            if ($abstract == $delAbstract) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * @param string $abstract
     * @param $resolver
     * @return $this
     */
    public function singleton(string $abstract, $resolver = null)
    {
        return $this->bind($abstract, $resolver, true);
    }

    /**
     * @param string $abstract
     * @param $resolver
     * @param bool $single
     * @return $this
     */
    public function bind(string $abstract, $resolver = null, $single = false)
    {
        if (is_null($resolver)) {
            $resolver = $abstract;
        }

        if (!$resolver instanceof Closure) {
            $resolver = $this->buildClosure($abstract, $resolver);
        }

        $this->bindings[$abstract] = [$resolver, $single];

        return $this;
    }

    /**
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * @param string $alias
     */
    public function removeBinding(string $alias): void
    {
        unset($this->bindings[$alias]);
    }

    /**
     * @param string $abstract
     * @param array $parameters
     * @return mixed|object
     * @throws Exception
     */
    public function make(string $abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);
        $binding = $this->bindings[$abstract] ?? null;

        if (is_null($binding)) {
            return $this->createInstance($abstract, $parameters);
        }

        if (is_object($binding)) {
            return $binding;
        }

        unset($this->bindings[$abstract]);
        try {
            $instance = $this->createInstanceClosure($binding[0], $parameters);
        } finally {
            $this->bindings[$abstract] = $binding;
        }

        //singleton
        if (!empty($binding[1])) {
            $this->bindings[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * @param ReflectionFunctionAbstract $reflection
     * @param array $parameters
     * @return array
     * @throws Exception
     */
    public function resolveArguments(ReflectionFunctionAbstract $reflection, array $parameters = []): array
    {
        $arguments = [];

        foreach ($reflection->getParameters() as $index => $parameter) {
            if (array_key_exists($parameter->getName(), $parameters)) {
                $arguments[] = $parameters[$parameter->getName()];
            } elseif (array_key_exists($index, $parameters)) {
                $arguments[] = $parameters[$parameter->getPosition()];
            } elseif (!is_null($parameter->getClass())) {
                $arguments[] = $this->get($parameter->getClass()->getName());
            } else {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $arguments[] = null;
                }
            }
        }

        return $arguments;
    }

    /**
     * @param $abstract
     * @param $resolver
     * @return Closure
     */
    private function buildClosure(string $abstract, $resolver)
    {
        return function ($parameters = []) use ($abstract, $resolver) {
            if (is_object($resolver)) {
                return $resolver;
            }

            return $this->make(
                $abstract === $resolver ? $abstract : $resolver,
                $parameters
            );
        };
    }

    /**
     * @param string $class
     * @param array $parameters
     * @return object
     * @throws Exception
     */
    private function createInstance(string $class, array $parameters)
    {
        if (!class_exists($class)) {
            throw new NotFoundException(sprintf("Undefined class or binding '%s'", $class));
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new ContainerException($e->getMessage(), $e->getCode(), $e);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf("Class '%s' can not be constructed", $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            $instance =  $reflection->newInstanceArgs($this->resolveArguments($constructor, $parameters));
        } else {
            $instance = $reflection->newInstance();
        }

        return $this->registerInstance($instance, $parameters);
    }

    /**
     * @param $target
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    private function createInstanceClosure(Closure $target, array $parameters) {
        try {
            $reflection = new ReflectionFunction($target);
        } catch (ReflectionException $e) {
            throw new ContainerException($e->getMessage(), $e->getCode(), $e);
        }

        return $reflection->invokeArgs($this->resolveArguments($reflection, $parameters));
    }

    /**
     * @param $instance
     * @param array $parameters
     * @return SingletonInterface
     */
    private function registerInstance($instance, array $parameters)
    {
        if ($parameters === [] && $instance instanceof SingletonInterface) {
            $alias = get_class($instance);

            if (!isset($this->bindings[$alias])) {
                $this->bindings[$alias] = $instance;
            }
        }

        return $instance;
    }
}
