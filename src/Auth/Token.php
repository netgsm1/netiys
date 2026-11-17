<?php

declare(strict_types=1);

namespace Netgsm\Iys\Auth;

/**
 * Identity API'den alınan token çifti. Değerler dökümlerde görünmez.
 */
final class Token
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $accessToken,
        #[\SensitiveParameter] private readonly ?string $refreshToken,
        public readonly int $expiresAt,
    ) {
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * Süresinin dolmasına `$leewaySeconds` saniyeden fazla kaldıysa kullanılabilir.
     */
    public function isUsable(?int $now = null, int $leewaySeconds = 60): bool
    {
        return $this->expiresAt - $leewaySeconds > ($now ?? time());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '***',
            'refreshToken' => $this->refreshToken === null ? null : '***',
            'expiresAt' => $this->expiresAt,
        ];
    }
}
