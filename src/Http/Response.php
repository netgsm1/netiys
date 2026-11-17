<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

final class Response implements \JsonSerializable
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers Başlık adları küçük harfe çevrilir.
     * @param string                $body    Token ya da kişisel veri taşıyabilir; hata yığınlarına yazılmaz.
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        #[\SensitiveParameter] public readonly string $body = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Gövde (access token, refresh token, alıcı bilgileri) dökümlerde görünmez.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        foreach (['set-cookie', 'authorization', 'proxy-authorization'] as $name) {
            if (isset($headers[$name])) {
                $headers[$name] = '***';
            }
        }

        return [
            'status' => $this->status,
            'headers' => $headers,
            'body' => sprintf('*** (%d bayt)', strlen($this->body)),
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
        throw new \LogicException('Response serileştirilemez: token ve kişisel veri taşıyabilir.');
    }
}
