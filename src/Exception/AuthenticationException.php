<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * Token alınamadı ya da yeni token ile de 401 alındı. Kimlik bilgilerini ve hesabı kontrol edin.
 *
 * Identity API (`/auth/v3`) hatalarında {@see self::identityCode()} `invalid_credentials`,
 * `ip_not_allowed`, `sub_user_not_permitted` gibi bir değer döner.
 */
final class AuthenticationException extends ApiException
{
    private ?string $identityCode = null;

    public static function fromIdentity(
        int $httpStatus,
        ?string $identityCode,
        ?string $detail,
        ?string $requestId,
    ): self {
        $message = sprintf('Netgsm Identity API token vermedi: HTTP %d', $httpStatus);
        if ($identityCode !== null) {
            $message .= ', code ' . $identityCode;
        }
        if ($detail !== null && $detail !== '') {
            $message .= ' (' . $detail . ')';
        }

        $exception = new self($message, $httpStatus, null, $detail, [], $requestId, $requestId);
        $exception->identityCode = $identityCode;

        return $exception;
    }

    public function identityCode(): ?string
    {
        return $this->identityCode;
    }
}
