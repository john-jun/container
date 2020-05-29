<?php
declare(strict_types=1);
namespace Air\Container;

use Air\Container\Exception\ContainerException;
use Air\Container\Exception\NotFoundException;
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
     * @var array
     */
    private $bindings = [];

    /**
     * @var array
     */
    private $aliases = [];

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

        return $this->aliases[$abstract];
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
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
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

        if (is_object($resolver) && !is_callable($resolver)) {
            $this->bindings[$abstract] = $resolver;
        } else {
            $this->bindings[$abstract] = [$resolver, $single];
        }

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
     * @param string $class
     * @param array $parameters
     * @return mixed|object
     * @throws Exception
     */
    public function make(string $class, array $parameters = [])
    {
        $binding = $this->bindings[$class = $this->getAlias($class)] ?? null;

        switch (gettype($binding)) {
            case 'float':
            case 'object':
            case 'double':
            case 'integer':
            case 'resource':
                return $binding;

            case 'string':
                return $this->make($binding, $parameters);

            case 'null':
                $instance = $this->createInstance($class, $parameters);
                break;

            case 'array':
            default:
                if ($binding[0] === $class) {
                    $instance = $this->createInstance($class, $parameters);
                } else {
                    $instance = $this->evaluateBinding($class, $binding[0], $parameters);
                }
        }

        if (($parameters === [] && $instance instanceof SingletonInterface) || !empty($binding[1])) {
            $this->bindings[$class] = $instance;
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
     * @param string $alias
     * @param $target
     * @param array $parameters
     * @return mixed|object
     * @throws Exception
     */
    private function evaluateBinding(string $alias, $target, array $parameters) {
        if (is_string($target)) {
            return $this->make($target, $parameters);
        }

        if (is_callable($target)) {
            try {
                $reflection = new ReflectionFunction($target);
            } catch (ReflectionException $e) {
                throw new ContainerException($e->getMessage(), $e->getCode(), $e);
            }

            return $reflection->invokeArgs($this->resolveArguments($reflection, $parameters));
        }

        //Resolver instance (i.e. [ClassName::class, 'method'])
        if (is_array($target) && isset($target[1])) {
            [$resolver, $method] = $target;
            $resolver = $this->get($resolver);

            try {
                $method = new ReflectionMethod($resolver, $method);
                $method->setAccessible(true);
            } catch (ReflectionException $e) {
                throw new ContainerException($e->getMessage(), $e->getCode(), $e);
            }

            //Invoking factory method with resolved arguments
            return $method->invokeArgs($resolver, $this->resolveArguments($method, $parameters));
        }

        throw new ContainerException(sprintf("Invalid binding for '%s'", $alias));
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
            return $reflection->newInstanceArgs($this->resolveArguments($constructor, $parameters));
        }

        return $reflection->newInstance();
    }
}
