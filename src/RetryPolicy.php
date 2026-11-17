<?php

declare(strict_types=1);

namespace Netgsm\Iys;

/**
 * Tekrar denenebilir hatalarda (503/141, 429/150 ve okuma uçlarında 424, 500, ağ hatası) bekleme kuralı.
 *
 * Bekleme artarak büyür (1 sn, 2 sn, 4 sn…); yanıtta `Retry-After` varsa ona uyulur.
 */
final class RetryPolicy
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /**
     * @param int                     $maxRetries  İlk denemeden sonraki en fazla tekrar sayısı.
     * @param int                     $baseDelayMs İlk bekleme, milisaniye.
     * @param int                     $maxDelayMs  Tek bir beklemenin üst sınırı, milisaniye.
     * @param (\Closure(int): void)|null $sleeper  Bekleme fonksiyonu (milisaniye); testler için.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly int $baseDelayMs = 1000,
        public readonly int $maxDelayMs = 30000,
        ?\Closure $sleeper = null,
    ) {
        if ($maxRetries < 0 || $baseDelayMs < 0 || $maxDelayMs < $baseDelayMs) {
            throw new Exception\ConfigurationException('Geçersiz tekrar deneme ayarı.');
        }
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function canRetry(int $attempt): bool
    {
        return $attempt < $this->maxRetries;
    }

    /**
     * @param int $attempt 0'dan başlayan tekrar sırası.
     */
    public function wait(int $attempt, ?string $retryAfter = null): void
    {
        ($this->sleeper)($this->delayMs($attempt, $retryAfter));
    }

    public function delayMs(int $attempt, ?string $retryAfter = null): int
    {
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return min((int) trim($retryAfter) * 1000, $this->maxDelayMs);
        }

        return min($this->baseDelayMs * (2 ** min($attempt, 20)), $this->maxDelayMs);
    }
}
