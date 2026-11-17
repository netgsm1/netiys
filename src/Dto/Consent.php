<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

use Netgsm\Iys\Enum\ConsentSource;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Support\Mask;
use Netgsm\Iys\Support\Validate;

/**
 * İYS'ye kaydedilecek tek bir izin (ya da ret) kaydı: `POST /consents` içindeki `permissions[]` öğesi.
 *
 * Yalnızca alıcıdan gerçekten alınmış izinler kaydedilir; İYS'ye yüklenen kayıtlar silinemez.
 */
final class Consent
{
    public readonly string $recipient;

    public readonly string $consentDate;

    /** @var list<int>|null */
    public readonly ?array $retailerAccess;

    /**
     * @param string                    $recipient    `+90XXXXXXXXXX` ya da e-posta adresi.
     * @param string|\DateTimeInterface $consentDate  İznin alındığı an (`YYYY-MM-DD HH:mm:ss`), isteğin gönderildiği an değil.
     * @param int|null                  $retailerCode Bayi yapınız yoksa göndermeyin.
     * @param list<int>|null            $retailerAccess Bayi yapınız yoksa göndermeyin.
     */
    public function __construct(
        string $recipient,
        public readonly RecipientType $recipientType,
        public readonly ConsentType $type,
        public readonly ConsentSource $source,
        public readonly ConsentStatus $status,
        string|\DateTimeInterface $consentDate,
        public readonly ?int $retailerCode = null,
        ?array $retailerAccess = null,
    ) {
        $this->recipient = Validate::recipient($recipient, 'recipient');
        $this->consentDate = Validate::dateTime($consentDate, 'consentDate');

        if ($retailerCode !== null && $retailerCode < 0) {
            throw new InvalidArgumentException('retailerCode 0 ya da pozitif bir tam sayı olmalıdır.');
        }
        if ($retailerAccess !== null) {
            foreach ($retailerAccess as $code) {
                if (!is_int($code) || $code < 0) {
                    throw new InvalidArgumentException('retailerAccess yalnızca 0 ya da pozitif tam sayılar içerebilir.');
                }
            }
            $retailerAccess = array_values($retailerAccess);
        }
        $this->retailerAccess = $retailerAccess;
    }

    /**
     * Dizi biçiminden oluşturur (v2 kullanımından geçişi kolaylaştırır).
     *
     * @param array<string, mixed> $data `recipient`, `recipientType`, `type`, `source`, `status`, `consentDate`
     *                                   ve isteğe bağlı `retailerCode`, `retailerAccess`.
     */
    public static function fromArray(array $data): self
    {
        foreach (['recipient', 'recipientType', 'type', 'source', 'status', 'consentDate'] as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException(sprintf('%s alanı zorunludur.', $field));
            }
        }

        $retailerCode = $data['retailerCode'] ?? null;
        $retailerAccess = $data['retailerAccess'] ?? null;

        return new self(
            self::string($data['recipient'], 'recipient'),
            self::enum(RecipientType::class, $data['recipientType'], 'recipientType'),
            self::enum(ConsentType::class, $data['type'], 'type'),
            self::enum(ConsentSource::class, $data['source'], 'source'),
            self::enum(ConsentStatus::class, $data['status'], 'status'),
            $data['consentDate'] instanceof \DateTimeInterface
                ? $data['consentDate']
                : self::string($data['consentDate'], 'consentDate'),
            $retailerCode === null ? null : (is_int($retailerCode) ? $retailerCode : throw new InvalidArgumentException('retailerCode tam sayı olmalıdır.')),
            $retailerAccess === null ? null : (is_array($retailerAccess) ? array_values($retailerAccess) : throw new InvalidArgumentException('retailerAccess dizi olmalıdır.')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'recipient' => $this->recipient,
            'recipientType' => $this->recipientType->value,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'consentDate' => $this->consentDate,
        ];
        if ($this->retailerCode !== null) {
            $data['retailerCode'] = $this->retailerCode;
        }
        if ($this->retailerAccess !== null) {
            $data['retailerAccess'] = $this->retailerAccess;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['recipient' => Mask::recipient($this->recipient)] + $this->toArray();
    }

    private static function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s metin olmalıdır.', $field));
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $field): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            $allowed = implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases()));
            throw new InvalidArgumentException(sprintf('%s şu değerlerden biri olmalıdır: %s', $field, $allowed));
        }

        return $case;
    }
}
