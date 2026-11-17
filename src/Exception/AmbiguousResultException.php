<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * `POST /consents` sonucu belirsiz: 424 (112), 500 (110), beklenmeyen yanıt ya da istek gönderildikten
 * sonra oluşan ağ hatası. Kayıtların İYS'ye ulaşıp ulaşmadığı bilinmiyor.
 *
 * Aynı partiyi körlemesine yeniden göndermeyin. Webhook bildirimlerini ve `searchConsents()` sonucunu
 * kontrol edin; belirsizlik sürerse {@see self::$threadId} / {@see self::$requestId} ile teknik desteğe
 * (teknikdestek@netgsm.com.tr) başvurun.
 */
final class AmbiguousResultException extends \RuntimeException implements IysException
{
    public function __construct(
        string $message,
        public readonly ?string $requestId,
        public readonly ?string $threadId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
