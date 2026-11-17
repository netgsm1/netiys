<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Support\Mask;
use Netgsm\Iys\Support\Validate;

/**
 * `GET /consents` için tek bir sorgu kaydı.
 */
final class ConsentQuery
{
    public readonly string $recipient;

    public readonly ?string $refId;

    /**
     * @param string      $recipient `+90XXXXXXXXXX` ya da e-posta adresi.
     * @param string|null $refId     Kayda özel referans; gönderilmezse istekteki refId kullanılır.
     */
    public function __construct(
        #[\SensitiveParameter] string $recipient,
        public readonly RecipientType $recipientType,
        public readonly ConsentType $type,
        ?string $refId = null,
    ) {
        $this->recipient = Validate::recipient($recipient, 'recipient');
        $this->refId = Validate::refId($refId, 'refId');
    }

    /**
     * @param array<string, mixed> $data `recipient`, `recipientType`, `type`, isteğe bağlı `refId`.
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $recipient = $data['recipient'] ?? null;
        $recipientType = $data['recipientType'] ?? null;
        $type = $data['type'] ?? null;
        $refId = $data['refId'] ?? null;

        if (!is_string($recipient)) {
            throw new InvalidArgumentException('recipient alanı zorunludur.');
        }
        if (!$recipientType instanceof RecipientType) {
            $recipientType = is_string($recipientType) ? RecipientType::tryFrom($recipientType) : null;
            if ($recipientType === null) {
                throw new InvalidArgumentException('recipientType BIREYSEL ya da TACIR olmalıdır.');
            }
        }
        if (!$type instanceof ConsentType) {
            $type = is_string($type) ? ConsentType::tryFrom($type) : null;
            if ($type === null) {
                throw new InvalidArgumentException('type MESAJ, ARAMA ya da EPOSTA olmalıdır.');
            }
        }
        if ($refId !== null && !is_string($refId)) {
            throw new InvalidArgumentException('refId metin olmalıdır.');
        }

        return new self($recipient, $recipientType, $type, $refId);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'recipient' => $this->recipient,
            'recipientType' => $this->recipientType->value,
            'type' => $this->type->value,
        ];
        if ($this->refId !== null) {
            $data['refId'] = $this->refId;
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
}
