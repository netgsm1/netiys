<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

/**
 * İYS'nin reddettiği kayıt (`data.erroritem[]`). Bu kayıt kaydedilmemiştir; düzeltilip yeniden gönderilir.
 */
final class RejectedConsent
{
    /**
     * @param int    $index   Kaydın gönderilen listedeki sırası (0'dan başlar).
     * @param string $field   Hatalı alan; mantık buna göre kurulur.
     * @param string $message İYS'den gelen mesaj; loglanır.
     */
    public function __construct(
        public readonly int $index,
        public readonly string $field,
        public readonly string $message,
    ) {
    }
}
