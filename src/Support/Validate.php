<?php

declare(strict_types=1);

namespace Netgsm\Iys\Support;

use Netgsm\Iys\Exception\InvalidArgumentException;

/**
 * İstek gönderilmeden yapılan yerel doğrulamalar. Hata mesajlarında kişisel veri yer almaz.
 *
 * @internal
 */
final class Validate
{
    // `$` sondaki "\n"i de kabul eder; desenler bu yüzden `\z` ile biter.
    private const PHONE_PATTERN = '/^\+90\d{10}\z/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/';
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,64}\z/';
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Telefon `+90XXXXXXXXXX`, e-posta geçerli bir adres; en fazla 255 karakter.
     */
    public static function recipient(#[\SensitiveParameter] string $recipient, string $field): string
    {
        $recipient = trim($recipient);
        if ($recipient === '' || strlen($recipient) > 255) {
            throw new InvalidArgumentException(sprintf('%s boş olamaz ve en fazla 255 karakter olabilir.', $field));
        }

        if (str_contains($recipient, '@')) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(sprintf('%s geçerli bir e-posta adresi değil.', $field));
            }

            return $recipient;
        }

        if (preg_match(self::PHONE_PATTERN, $recipient) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s telefon numarası +90XXXXXXXXXX biçiminde olmalıdır (örnek: +905XXXXXXXXX).',
                $field,
            ));
        }

        return $recipient;
    }

    /**
     * `YYYY-MM-DD HH:mm:ss` biçiminde, takvimde var olan bir tarih.
     */
    public static function dateTime(string|\DateTimeInterface $value, string $field): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::DATE_FORMAT);
        }

        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);
        if (
            preg_match(self::DATE_PATTERN, $value) !== 1
            || $parsed === false
            || $parsed->format(self::DATE_FORMAT) !== $value
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s YYYY-MM-DD HH:mm:ss biçiminde geçerli bir tarih olmalıdır (örnek: 2026-02-20 16:46:00).',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @return non-empty-string|null
     */
    public static function refId(?string $refId, string $field): ?string
    {
        if ($refId === null) {
            return null;
        }
        if ($refId === '' || strlen($refId) > 255) {
            throw new InvalidArgumentException(sprintf('%s 1-255 karakter olmalıdır.', $field));
        }
        if (!self::isUtf8($refId)) {
            throw new InvalidArgumentException(sprintf('%s geçerli UTF-8 olmalıdır.', $field));
        }

        return $refId;
    }

    /**
     * mbstring gerektirmeden UTF-8 denetimi; geçersiz dizide PCRE `u` kipi eşleşmez.
     */
    public static function isUtf8(#[\SensitiveParameter] string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    public static function requestId(string $requestId): string
    {
        if (preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1) {
            throw new InvalidArgumentException(
                'X-Request-Id 8-64 karakter olmalı ve yalnızca A-Z a-z 0-9 . _ - içermelidir.',
            );
        }

        return $requestId;
    }

    public static function brandCode(int $brandCode): int
    {
        if ($brandCode < 1 || $brandCode > 2147483647) {
            throw new InvalidArgumentException('brandCode 1 ile 2147483647 arasında bir tam sayı olmalıdır.');
        }

        return $brandCode;
    }
}
