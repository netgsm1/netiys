<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * 429 / errorCode 150: yeniden deneme hakları tükendi.
 */
final class RateLimitException extends ApiException
{
}
