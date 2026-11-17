<?php

declare(strict_types=1);

namespace Netgsm\Iys\Http;

/**
 * İYS API zarfı: `{ success, errorCode, message, data }`.
 *
 * Gövde JSON değilse ya da zarf beklenen biçimde değilse {@see self::$errorCode} null olur.
 */
final class ApiResponse implements \JsonSerializable
{
    private function __construct(
        public readonly int $httpStatus,
        public readonly bool $success,
        public readonly ?int $errorCode,
        public readonly ?string $message,
        #[\SensitiveParameter] public readonly mixed $data,
        public readonly ?string $requestId,
        public readonly ?string $threadId,
    ) {
    }

    public static function fromResponse(Response $response, ?string $requestId): self
    {
        $decoded = null;
        if ($response->body !== '') {
            try {
                $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        $threadId = $response->header('X-Thread-Id');

        if (!is_array($decoded) || !isset($decoded['errorCode']) || !is_numeric($decoded['errorCode'])) {
            return new self($response->status, false, null, null, null, $requestId, $threadId);
        }

        $message = $decoded['message'] ?? null;

        return new self(
            $response->status,
            ($decoded['success'] ?? false) === true,
            (int) $decoded['errorCode'],
            is_scalar($message) ? (string) $message : null,
            $decoded['data'] ?? null,
            $requestId,
            $threadId,
        );
    }

    /**
     * `data` alıcı bilgileri taşıyabilir; dökümlerde yalnızca türü ve boyutu görünür.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'httpStatus' => $this->httpStatus,
            'success' => $this->success,
            'errorCode' => $this->errorCode,
            'message' => $this->message,
            'data' => match (true) {
                $this->data === null => null,
                is_array($this->data) => sprintf('*** (%d öğe)', count($this->data)),
                default => '***',
            },
            'requestId' => $this->requestId,
            'threadId' => $this->threadId,
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
        throw new \LogicException('ApiResponse serileştirilemez: kişisel veri taşıyabilir.');
    }

    public function isSuccessful(): bool
    {
        return $this->errorCode === 0 && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * @return array<mixed>
     */
    public function dataArray(): array
    {
        return is_array($this->data) ? $this->data : [];
    }
}
