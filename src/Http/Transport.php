<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

use Netgsm\Iys\Exception\TransportException;

/**
 * HTTP gönderim katmanı. Varsayılanı {@see CurlTransport}; testlerde sahte bir uygulama verilir.
 */
interface Transport
{
    /**
     * HTTP yanıtı alındığında durum kodundan bağımsız olarak döner; yalnızca yanıt alınamazsa fırlatır.
     *
     * @throws TransportException
     */
    public function send(Request $request): Response;
}
