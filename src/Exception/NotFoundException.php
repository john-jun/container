<?php
declare(strict_types=1);
namespace Air\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Class NotFoundException
 * @package Air\Container\Exception
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
