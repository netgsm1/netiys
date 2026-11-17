<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

use Netgsm\Iys\Exception\ConfigurationException;
use Netgsm\Iys\Exception\TransportException;

/**
 * cURL ile HTTPS gönderimi. Sertifika doğrulaması her zaman açıktır ve kapatılamaz;
 * yalnızca HTTPS kabul edilir, yönlendirme izlenmez.
 */
final class CurlTransport implements Transport
{
    /**
     * Bu cURL hatalarında bağlantı kurulamamıştır; istek sunucuya ulaşmamıştır.
     * 5 proxy çözümlenemedi, 6 host çözümlenemedi, 7 bağlanılamadı, 35 TLS el sıkışması,
     * 51/60 sertifika doğrulanamadı, 58 istemci sertifikası, 77 CA dosyası okunamadı.
     */
    private const NOT_SENT_ERRORS = [5, 6, 7, 35, 51, 58, 60, 77];

    /**
     * @param int         $timeout        İsteğin toplam süre sınırı, saniye.
     * @param int         $connectTimeout Bağlantı kurma süre sınırı, saniye.
     * @param string|null $caBundle       Sistem CA deposu yerine kullanılacak CA dosyası (isteğe bağlı).
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly ?string $caBundle = null,
    ) {
        if ($timeout < 1 || $connectTimeout < 1) {
            throw new ConfigurationException('Zaman aşımı değerleri en az 1 saniye olmalıdır.');
        }
        if (!extension_loaded('curl')) {
            throw new ConfigurationException('CurlTransport için curl eklentisi gereklidir.');
        }
    }

    public function send(Request $request): Response
    {
        if (!str_starts_with(strtolower($request->url), 'https://')) {
            throw new ConfigurationException('Yalnızca HTTPS adreslerine istek gönderilir.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        $handle = curl_init();
        // @phpstan-ignore argument.type (URL ve metot Request üzerinden gelir; boş değer cURL hatası olarak döner)
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($errno !== 0 || !is_string($body)) {
            throw new TransportException(
                sprintf('HTTP isteği tamamlanamadı (cURL %d: %s)', $errno, $error),
                !in_array($errno, self::NOT_SENT_ERRORS, true),
                $request->header('X-Request-Id'),
                $errno,
            );
        }

        return new Response($status, $responseHeaders, $body);
    }
}
