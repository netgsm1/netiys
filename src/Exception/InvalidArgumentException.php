<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * İstek gönderilmeden, yerel doğrulamada yakalanan hatalı girdi.
 */
class InvalidArgumentException extends \InvalidArgumentException implements IysException
{
}
