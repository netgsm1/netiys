<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Support\Mask;

/**
 * `GET /consents` sonucundaki tek bir kayıt.
 *
 * İzin kararı yalnızca {@see self::isApproved()} ile verilir: `RET`, `null` ya da kayıt bulunamadı
 * (81) izin yok demektir; 112 ve 120 "bilinmiyor" demektir ve izin varmış gibi davranılmaz.
 */
final class ConsentRecord
{
    public const ERROR_NONE = 0;
    public const ERROR_NOT_FOUND = 81;
    public const ERROR_DEPENDENCY = 112;
    public const ERROR_BAD_REQUEST = 120;

    /**
     * @param array<string, mixed> $raw API'den gelen ham kayıt.
     */
    public function __construct(
        public readonly string $recipient,
        public readonly ?RecipientType $recipientType,
        public readonly ?ConsentType $type,
        public readonly ?ConsentStatus $status,
        public readonly ?string $source,
        public readonly ?string $consentDate,
        public readonly ?int $retailerAccessCount,
        public readonly int $errorCode,
        public readonly ?string $message,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, ConsentQuery $query): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;

        $recipientType = $string('recipientType');
        $type = $string('type');
        $status = $string('status');
        $retailerAccessCount = $data['retailerAccessCount'] ?? null;
        $errorCode = $data['errorCode'] ?? 0;

        /** @var array<string, mixed> $data */
        return new self(
            $string('recipient') ?? $query->recipient,
            $recipientType !== null ? RecipientType::tryFrom($recipientType) : $query->recipientType,
            $type !== null ? ConsentType::tryFrom($type) : $query->type,
            $status !== null ? ConsentStatus::tryFrom($status) : null,
            $string('source'),
            $string('consentDate'),
            is_numeric($retailerAccessCount) ? (int) $retailerAccessCount : null,
            is_numeric($errorCode) ? (int) $errorCode : 0,
            $string('message'),
            $data,
        );
    }

    /**
     * Tekil sorguda İYS'de kayıt bulunamadığında (404 / 81) kullanılır.
     */
    public static function notFound(ConsentQuery $query, ?string $message = null): self
    {
        return new self(
            $query->recipient,
            $query->recipientType,
            $query->type,
            null,
            null,
            null,
            null,
            self::ERROR_NOT_FOUND,
            $message,
        );
    }

    /**
     * Ticari ileti için izin var mı? Yalnızca `status: ONAY` ve hatasız kayıt izin demektir.
     */
    public function isApproved(): bool
    {
        return $this->errorCode === self::ERROR_NONE && $this->status === ConsentStatus::ONAY;
    }

    public function isRejected(): bool
    {
        return $this->errorCode === self::ERROR_NONE && $this->status === ConsentStatus::RET;
    }

    public function isNotFound(): bool
    {
        return $this->errorCode === self::ERROR_NOT_FOUND;
    }

    /**
     * Sonuç bilinmiyor (112 İYS'den yanıt alınamadı, 120 hatalı kayıt); daha sonra yeniden sorgulayın.
     */
    public function isUnknown(): bool
    {
        return !in_array($this->errorCode, [self::ERROR_NONE, self::ERROR_NOT_FOUND], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'recipient' => Mask::recipient($this->recipient),
            'recipientType' => $this->recipientType,
            'type' => $this->type,
            'status' => $this->status,
            'source' => $this->source,
            'consentDate' => $this->consentDate,
            'retailerAccessCount' => $this->retailerAccessCount,
            'errorCode' => $this->errorCode,
            'message' => $this->message,
        ];
    }
}
