<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
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
     * Gövde (şifre, kişisel veri) ve Authorization başlığı dökümlerde görünmez.
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
            'url' => $this->url,
            'headers' => $headers,
            'body' => $this->body === null ? null : sprintf('*** (%d bayt)', strlen($this->body)),
        ];
    }
}
