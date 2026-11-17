<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

final class Request implements \JsonSerializable
{
    /**
     * Adres satırı (sorguda alıcı bilgisi), başlıklar (access token) ve gövde (şifre, refresh token,
     * kişisel veri) hassastır; hata yığınlarına parametre olarak yazılmaz.
     *
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        #[\SensitiveParameter] public readonly string $url,
        #[\SensitiveParameter] public readonly array $headers = [],
        #[\SensitiveParameter] public readonly ?string $body = null,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Sorgu değerleri, gövde (şifre, kişisel veri) ve Authorization başlığı dökümlerde görünmez.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $headers[$key] = '***';
            }
        }

        return [
            'method' => $this->method,
            'url' => self::maskQuery($this->url),
            'headers' => $headers,
            'body' => $this->body === null ? null : sprintf('*** (%d bayt)', strlen($this->body)),
        ];
    }

    /**
     * `json_encode()` (ör. Monolog bağlamı) de maskelenmiş görünümü yazar.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Request serileştirilemez: şifre, token ve kişisel veri taşıyabilir.');
    }

    private static function maskQuery(string $url): string
    {
        $position = strpos($url, '?');
        if ($position === false) {
            return $url;
        }

        $query = substr($url, $position + 1);

        return substr($url, 0, $position) . ($query === '' ? '' : sprintf('?*** (%d parametre)', count(explode('&', $query))));
    }
}
