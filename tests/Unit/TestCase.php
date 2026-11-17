<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Auth\InMemoryTokenStore;
use Netgsm\Iys\Auth\TokenStore;
use Netgsm\Iys\Config;
use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Enum\ConsentSource;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\IysClient;
use Netgsm\Iys\RetryPolicy;
use Netgsm\Iys\Tests\Fake\FakeTransport;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Testlerde gerçek numara kullanılmaz; bu değerler yalnızca biçimi doğrular. */
    protected const PHONE = '+905000000001';
    protected const PHONE_2 = '+905000000002';
    protected const EMAIL = 'test@example.com';
    protected const BRAND_CODE = 123456;
    protected const PASSWORD = 'gizli-sifre-123';

    protected FakeTransport $transport;

    /** @var list<int> */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
    }

    protected function config(): Config
    {
        return new Config('8500000000', self::PASSWORD, self::BRAND_CODE);
    }

    protected function client(?TokenStore $store = null, int $maxRetries = 2, ?Config $config = null): IysClient
    {
        $sleeps = &$this->sleeps;
        $policy = new RetryPolicy($maxRetries, 1000, 30000, static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });

        return new IysClient($config ?? $this->config(), $this->transport, $store ?? new InMemoryTokenStore(), $policy);
    }

    protected function consent(string $recipient = self::PHONE): Consent
    {
        return new Consent(
            $recipient,
            RecipientType::BIREYSEL,
            ConsentType::MESAJ,
            ConsentSource::HS_WEB,
            ConsentStatus::ONAY,
            '2026-02-20 16:46:00',
        );
    }

    /**
     * @return array<mixed>
     */
    protected static function decodeBody(?string $body): array
    {
        self::assertNotNull($body);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
