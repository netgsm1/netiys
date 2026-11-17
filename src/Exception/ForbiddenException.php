<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * 403: yetki yok (60, 61) ya da hesapta İYS hizmeti aktif değil (63).
 */
final class ForbiddenException extends ApiException
{
}
