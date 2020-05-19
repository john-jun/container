<?php
declare(strict_types=1);

namespace Air\Container;

use Air\Container\Exception\BindingResolutionException;
use Air\Container\Exception\EntryNotFoundException;
use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

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
    private $instances;

    /**
     * @var array
     */
    private $bindings = [];

    /**
     * @var array
     */
    private $aliases = [];

    /**
     * @var array
     */
    private $with = [];

    /**
     * @var array
     */
    private $buildStackArgs = [];

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
     * @param $abstract
     * @param null $concrete
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * @param $abstract
     * @param $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $abstract = $this->getAlias($abstract);
        unset($this->instances[$abstract]);

        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * @param $abstract
     * @param null $concrete
     * @param bool $shared
     * @return $this
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $this->dropStaleInstance($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if (!$concrete instanceof Closure) {
            $concrete = $this->buildClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');

        return $this;
    }

    /**
     * @param $abstract
     * @param array $parameters
     * @return mixed|object|void
     * @throws BindingResolutionException
     * @throws EntryNotFoundException
     */
    public function make($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        /** 保存参数 **/
        $this->with[] = $parameters;

        $concrete = $this->getBuildClosure($abstract);
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        /** 是共享服务设置到 instances 里 **/
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        /** 删除保存参数 **/
        array_pop($this->with);

        return $object;
    }

    /**
     * @param $concrete
     * @return mixed|object|void
     * @throws BindingResolutionException
     * @throws EntryNotFoundException
     */
    public function build($concrete)
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        try {
            $reflector = new ReflectionClass($concrete);

            /** 检查类是否可实例化, 排除抽象类abstract和对象接口interface **/
            if (!$reflector->isInstantiable()) {
                return $this->throwNotInstantiable($concrete);
            }

            /** 当依赖没有找到 用户错误提示 **/
            $this->buildStackArgs[] = $concrete;

            /** 获取构造参数判断是否存在 **/
            $constructor = $reflector->getConstructor();
            if (is_null($constructor)) {
                array_pop($this->buildStackArgs);

                return new $concrete;
            }

            /** 取构造函数参数, 获取自动注入依赖项 **/
            $dependencies = $constructor->getParameters();
            $instances = $this->resolveDependencies($dependencies);

            array_pop($this->buildStackArgs);

            /** 创建一个类的实例，给出的参数将传递到类的构造函数 **/
            return $reflector->newInstanceArgs($instances);
        } catch (ReflectionException $e) {
            throw new EntryNotFoundException("Target [{$concrete}] not found");
        }
    }

    /**
     * @param $alias
     * @param $abstract
     */
    public function alias($alias, $abstract)
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * @param $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        return isset($this->instances[$abstract]) || (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * @param $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
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
     * 获取对象绑定参数
     * @return array|mixed
     */
    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * 判断参数是否存在
     * @param $dependency
     * @return bool
     */
    protected function hasParameterOverride(ReflectionParameter $dependency)
    {
        return isset($this->getLastParameterOverride()[$dependency->getPosition()]) ||
            array_key_exists($dependency->getName(), $this->getLastParameterOverride());
    }

    /**
     * @param ReflectionParameter $dependency
     * @return mixed
     */
    protected function getParameterOverride(ReflectionParameter $dependency)
    {
        return isset($this->getLastParameterOverride()[$dependency->getPosition()])
            ? $this->getLastParameterOverride()[$dependency->getPosition()]
            : $this->getLastParameterOverride()[$dependency->getName()];
    }

    /**
     * @param array $dependencies
     * @return array
     * @throws EntryNotFoundException
     * @throws ReflectionException
     */
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            /**@var $dependency ReflectionParameter**/
            if (is_null($dependency->getClass()) || !$dependency->getClass()->isInstantiable()) {
                $results[] = $this->resolvePrimitive($dependency);
            } else {
                $results[] = $this->resolveClass($dependency);
            }
        }

        return $results;
    }

    /**
     * @param ReflectionParameter $parameter
     * @return mixed|object|void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->getName());
        } catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * 解析参数
     * @param ReflectionParameter $parameter
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function resolvePrimitive(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionException($message);
    }

    /**
     * @param $concrete
     * @throws BindingResolutionException
     */
    protected function throwNotInstantiable($concrete)
    {
        if (!empty($this->buildStackArgs)) {
            $previous = implode(', ', $this->buildStackArgs);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * @param $abstract
     * @return mixed
     */
    protected function getBuildClosure($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * 构建一个闭包并返回
     * @param $abstract
     * @param $concrete
     * @return Closure
     */
    protected function buildClosure($abstract, $concrete)
    {
        return function (Container $container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete, $parameters);
        };
    }

    /**
     * @param $delAbstract
     */
    protected function removeAlias($delAbstract)
    {
        foreach ($this->aliases as $alias => $abstract) {
            if ($abstract == $delAbstract) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * @param $abstract
     */
    protected function dropStaleInstance($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * @param $concrete
     * @param $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * @param string $id
     * @return mixed|object|void
     * @throws EntryNotFoundException
     */
    public function get($id)
    {
        if ($this->has($id)) {
            return $this->make($id);
        }

        throw new EntryNotFoundException("Target [{$id}] not found");
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || $this->isAlias($id);
    }
}
