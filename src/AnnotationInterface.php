<?php
declare(strict_types=1);

namespace Air\Container;

/**
 * Interface AnnotationInterface
 * @package Air\Container
 */
interface AnnotationInterface
{
    public function classSet(string $className): void;
    public function methodSet(string $className, ?string $target): void;
    public function propertySet(string $className, ?string $target): void;
}
