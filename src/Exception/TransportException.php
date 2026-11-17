<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * Ağ ya da TLS hatası: sunucudan HTTP yanıtı alınamadı.
 *
 * {@see self::$requestMayHaveBeenSent} false ise bağlantı hiç kurulamamıştır
 * (DNS, bağlantı reddi, TLS doğrulaması); istek sunucuya ulaşmamıştır.
 */
final class TransportException extends \RuntimeException implements IysException
{
    public function __construct(
        string $message,
        public readonly bool $requestMayHaveBeenSent,
        public readonly ?string $requestId = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
