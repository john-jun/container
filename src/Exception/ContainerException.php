<?php
declare(strict_types=1);
namespace Air\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Class ContainerException
 * @package Air\Container\Exception
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
}
