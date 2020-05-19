<?php
declare(strict_types=1);

namespace Air\Container\Annotation;

use Air\Container\AnnotationInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Class AbstractAnnotation
 * @package Air\Container\Annotation
 */
class AbstractAnnotation implements AnnotationInterface
{
    public function __construct($value = null)
    {
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $val;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $properties = (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC);
        $result = [];

        foreach ($properties as $property) {
            $result[$property->getName()] = $property->getValue($this);
        }

        return $result;
    }

    public function classSet(string $className): void
    {

    }

    public function methodSet(string $className, ?string $target): void
    {

    }

    public function propertySet(string $className, ?string $target): void
    {

    }
}
