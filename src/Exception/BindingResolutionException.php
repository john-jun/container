<?php
declare(strict_types=1);
namespace Air\Container\Exception;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

class BindingResolutionException extends Exception implements NotFoundExceptionInterface
{
}
