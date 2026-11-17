<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

use Netgsm\Iys\Http\ApiResponse;

/**
 * API'nin hata yanıtı. Mantık {@see self::$errorCode} alanına göre kurulmalıdır.
 */
class ApiException extends \RuntimeException implements IysException
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?int $errorCode,
        public readonly ?string $apiMessage,
        #[\SensitiveParameter] public readonly array $data = [],
        public readonly ?string $requestId = null,
        public readonly ?string $threadId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode ?? 0, $previous);
    }

    public static function fromResponse(ApiResponse $response): static
    {
        $message = sprintf(
            'İYS API hatası: HTTP %d, errorCode %s%s',
            $response->httpStatus,
            $response->errorCode ?? '-',
            $response->message !== null && $response->message !== '' ? ' (' . $response->message . ')' : '',
        );

        // @phpstan-ignore new.static
        return new static(
            $message,
            $response->httpStatus,
            $response->errorCode,
            $response->message,
            is_array($response->data) ? $response->data : [],
            $response->requestId,
            $response->threadId,
        );
    }

    /**
     * `data` sunucunun hata ayrıntısıdır ve alıcı bilgisi içerebilir; dökümlerde yalnızca boyutu görünür.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'httpStatus' => $this->httpStatus,
            'errorCode' => $this->errorCode,
            'apiMessage' => $this->apiMessage,
            'data' => sprintf('*** (%d öğe)', count($this->data)),
            'requestId' => $this->requestId,
            'threadId' => $this->threadId,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'previous' => $this->getPrevious(),
        ];
    }
}
