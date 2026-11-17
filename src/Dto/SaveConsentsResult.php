<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

/**
 * `POST /consents` sonucu. Kısmi red normaldir: {@see self::$rejected} her zaman kontrol edilmelidir.
 */
final class SaveConsentsResult
{
    /**
     * @param list<RejectedConsent> $rejected
     */
    public function __construct(
        public readonly ?string $uid,
        public readonly array $rejected,
        public readonly ?string $requestId,
        public readonly ?string $threadId,
    ) {
    }

    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }

    /**
     * Reddedilen kayıtları, gönderilen listedeki karşılıklarıyla eşleştirir.
     *
     * @param list<Consent> $sent addConsents()'e verilen liste.
     *
     * @return list<array{consent: Consent|null, rejection: RejectedConsent}>
     */
    public function rejectedWith(array $sent): array
    {
        $pairs = [];
        foreach ($this->rejected as $rejection) {
            $pairs[] = ['consent' => $sent[$rejection->index] ?? null, 'rejection' => $rejection];
        }

        return $pairs;
    }
}
