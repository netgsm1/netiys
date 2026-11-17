<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Auth\InMemoryTokenStore;
use Netgsm\Iys\Auth\TokenProvider;
use Netgsm\Iys\Config;
use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Dto\ConsentRecord;
use Netgsm\Iys\Enum\ConsentSource;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\AmbiguousResultException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Http\ApiResponse;
use Netgsm\Iys\Http\Request;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Http\Transport;
use Netgsm\Iys\IysClient;
use Netgsm\Iys\RetryPolicy;
use Netgsm\Iys\Support\Mask;
use Netgsm\Iys\Support\Validate;
use Netgsm\Iys\Tests\Fake\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Şifre, token ve alıcı bilgileri dökümlere (print_r, var_dump) ve hata yığınlarına
 * (Throwable::getTrace(), hata izleme araçları) açık metin olarak düşmemelidir.
 */
final class SensitiveDataTest extends TestCase
{
    private const DIGITS = '905000000001';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Parametrelerin yığına yazıldığı ayar (geliştirme ortamlarında ve resmî Docker imajlarında varsayılan).
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    public function testRequestDumpHidesQueryValuesBodyAndAuthorization(): void
    {
        $request = new Request(
            'GET',
            'https://ext-api.netgsm.com.tr/iys/v1/consents?brandCode=1&data%5B0%5D%5Brecipient%5D=%2B' . self::DIGITS,
            ['Authorization' => 'Bearer secret-access'],
            '{"password":"' . self::PASSWORD . '"}',
        );

        $dump = print_r($request, true);

        self::assertStringNotContainsString(self::DIGITS, $dump);
        self::assertStringNotContainsString('secret-access', $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertStringContainsString('https://ext-api.netgsm.com.tr/iys/v1/consents?*** (2 parametre)', $dump);
    }

    public function testRequestDumpKeepsUrlWithoutQuery(): void
    {
        $dump = print_r(new Request('POST', 'https://ext-api.netgsm.com.tr/iys/v1/consents'), true);

        self::assertStringContainsString('https://ext-api.netgsm.com.tr/iys/v1/consents', $dump);
        self::assertStringNotContainsString('***', $dump);
    }

    public function testResponseDumpHidesBody(): void
    {
        $response = FakeTransport::token('secret-access', 'secret-refresh');

        $dump = print_r($response, true);

        self::assertStringNotContainsString('secret-access', $dump);
        self::assertStringNotContainsString('secret-refresh', $dump);
        self::assertStringContainsString('content-type', $dump);
    }

    public function testApiResponseDumpHidesData(): void
    {
        $response = ApiResponse::fromResponse(FakeTransport::api(200, 0, [['recipient' => self::PHONE]]), 'req-12345');

        $dump = print_r($response, true);

        self::assertStringNotContainsString(self::DIGITS, $dump);
        self::assertStringContainsString('*** (1 öğe)', $dump);
        self::assertSame(self::PHONE, $response->dataArray()[0]['recipient'], 'Veriye erişim değişmez.');
    }

    public function testInvalidRecipientIsNotWrittenToStackTrace(): void
    {
        try {
            new Consent(self::DIGITS, RecipientType::BIREYSEL, ConsentType::MESAJ, ConsentSource::HS_WEB, ConsentStatus::ONAY, '2026-01-01 10:00:00');
            self::fail('Exception bekleniyordu.');
        } catch (InvalidArgumentException $e) {
            self::assertTraceIsClean($e);
        }
    }

    public function testInvalidRecipientFromArrayIsNotWrittenToStackTrace(): void
    {
        try {
            ConsentQuery::fromArray(['recipient' => self::DIGITS, 'recipientType' => 'BIREYSEL', 'type' => 'MESAJ']);
            self::fail('Exception bekleniyordu.');
        } catch (InvalidArgumentException $e) {
            self::assertTraceIsClean($e);
        }
    }

    public function testAmbiguousResultTraceHasNoRecipientOrToken(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token('secret-access', 'secret-refresh'))
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(424, 112, [], 'Api Error'));

        try {
            $this->client()->addConsent($this->consent());
            self::fail('Exception bekleniyordu.');
        } catch (AmbiguousResultException $e) {
            self::assertTraceIsClean($e);
        }
    }

    public function testTransportErrorDuringQueryHasCleanTrace(): void
    {
        $client = $this->clientWith(function (Request $request): Response {
            if (str_ends_with((string) parse_url($request->url, PHP_URL_PATH), FakeTransport::TOKEN_PATH)) {
                return FakeTransport::token('secret-access', 'secret-refresh');
            }

            throw new TransportException('Bağlantı kurulamadı.', false);
        });

        try {
            $client->searchConsent(new ConsentQuery(self::PHONE, RecipientType::BIREYSEL, ConsentType::MESAJ));
            self::fail('Exception bekleniyordu.');
        } catch (TransportException $e) {
            self::assertTraceIsClean($e);
        }
    }

    public function testRefreshTokenIsNotWrittenToStackTrace(): void
    {
        $client = $this->clientWith(function (Request $request): Response {
            return match ((string) parse_url($request->url, PHP_URL_PATH)) {
                FakeTransport::TOKEN_PATH => FakeTransport::token('secret-access', 'secret-refresh', 30),
                FakeTransport::REFRESH_PATH => throw new TransportException('Bağlantı kurulamadı.', false),
                default => FakeTransport::api(200, 0, [['recipient' => self::PHONE, 'status' => 'ONAY', 'errorCode' => 0]]),
            };
        });
        $query = new ConsentQuery(self::PHONE, RecipientType::BIREYSEL, ConsentType::MESAJ);
        $client->searchConsent($query); // giriş; token ömrü leeway'den kısa olduğu için sonraki istek yeniler

        try {
            $client->searchConsent($query);
            self::fail('Exception bekleniyordu.');
        } catch (TransportException $e) {
            $frames = array_column($e->getTrace(), 'function');
            self::assertContains('refresh', $frames, 'Test, refresh çağrısının yığında olduğunu varsayar.');
            self::assertTraceIsClean($e);
        }
    }

    public function testJsonEncodeUsesMaskedView(): void
    {
        $json = json_encode([
            new Request('POST', 'https://ext-api.netgsm.com.tr/auth/v3/token?x=' . self::DIGITS, ['Authorization' => 'Bearer secret-access'], '{"password":"' . self::PASSWORD . '"}'),
            FakeTransport::token('secret-access', 'secret-refresh'),
            ApiResponse::fromResponse(FakeTransport::api(200, 0, [['recipient' => self::PHONE]]), null),
        ], JSON_THROW_ON_ERROR);

        foreach ([self::DIGITS, self::PASSWORD, 'secret-access', 'secret-refresh'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function unserializableObjects(): iterable
    {
        yield 'Config' => [static fn (): object => new Config('8500000000', self::PASSWORD, self::BRAND_CODE)];
        yield 'Request' => [static fn (): object => new Request('GET', 'https://ext-api.netgsm.com.tr/iys/v1/consents')];
        yield 'Response' => [static fn (): object => FakeTransport::token('secret-access', 'secret-refresh')];
        yield 'ApiResponse' => [static fn (): object => ApiResponse::fromResponse(FakeTransport::api(200, 0), null)];
    }

    /**
     * @param \Closure(): object $factory
     */
    #[DataProvider('unserializableObjects')]
    public function testSerializationIsRefused(\Closure $factory): void
    {
        $this->expectException(\LogicException::class);
        serialize($factory());
    }

    public function testSharedTokenStoreIsBoundToPassword(): void
    {
        $store = new InMemoryTokenStore();
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token('owner-access'), FakeTransport::token('other-access'))
            ->queue(FakeTransport::WEBHOOKS_PATH, FakeTransport::api(200, 0, ['uid' => 'a']), FakeTransport::api(200, 0, ['uid' => 'b']));

        $this->client($store)->saveWebhook('https://example.com/iys');
        $this->client($store, config: new Config('8500000000', 'yanlis-sifre', self::BRAND_CODE))->saveWebhook('https://example.com/iys');

        self::assertCount(2, $this->transport->requestsTo(FakeTransport::TOKEN_PATH), 'Farklı şifre kendi girişini yapmalı.');
        $webhooks = $this->transport->requestsTo(FakeTransport::WEBHOOKS_PATH);
        self::assertSame('Bearer other-access', $webhooks[1]->header('Authorization'));
    }

    public function testSamePasswordReusesSharedToken(): void
    {
        $store = new InMemoryTokenStore();
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token('owner-access'))
            ->queue(FakeTransport::WEBHOOKS_PATH, FakeTransport::api(200, 0, ['uid' => 'a']), FakeTransport::api(200, 0, ['uid' => 'b']));

        $this->client($store)->saveWebhook('https://example.com/iys');
        $this->client($store)->saveWebhook('https://example.com/iys');

        self::assertCount(1, $this->transport->requestsTo(FakeTransport::TOKEN_PATH));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function trailingNewlineInputs(): iterable
    {
        yield 'X-Request-Id' => [static fn (): string => Validate::requestId("abcdefgh\n")];
        yield 'tarih' => [static fn (): string => Validate::dateTime("2026-01-01 10:00:00\n", 'consentDate')];
    }

    /**
     * `$` sondaki satır sonunu da kabul ettiğinde X-Request-Id başlık bloğuna "\n" taşıyordu.
     *
     * @param \Closure(): mixed $validate
     */
    #[DataProvider('trailingNewlineInputs')]
    public function testTrailingNewlineIsRejected(\Closure $validate): void
    {
        $this->expectException(InvalidArgumentException::class);
        $validate();
    }

    public function testRecipientIsTrimmedNotPassedWithNewline(): void
    {
        self::assertSame(self::PHONE, Validate::recipient(self::PHONE . "\n", 'recipient'));
        self::assertSame(self::PHONE, (new ConsentQuery(self::PHONE . "\r\n", RecipientType::BIREYSEL, ConsentType::MESAJ))->recipient);
    }

    public function testCurlTransportRejectsHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Başlık kontrolü bağlantıdan önce yapılır; ağa istek gitmez.
        (new \Netgsm\Iys\Http\CurlTransport())->send(
            new Request('GET', 'https://ext-api.netgsm.com.tr/iys/v1/consents', ['X-Request-Id' => "abcdefgh\r\nX-Evil: 1"]),
        );
    }

    /**
     * @return iterable<string, array{class-string|string, string, string}>
     */
    public static function sensitiveParameters(): iterable
    {
        yield 'Config şifre' => [Config::class, '__construct', 'password'];
        yield 'TokenProvider refresh token' => [TokenProvider::class, 'refresh', 'refreshToken'];
        yield 'TokenProvider gövde' => [TokenProvider::class, 'requestToken', 'payload'];
        yield 'IysClient sorgu' => [IysClient::class, 'execute', 'query'];
        yield 'IysClient gövde' => [IysClient::class, 'execute', 'body'];
        yield 'Request adres' => [Request::class, '__construct', 'url'];
        yield 'Request başlıklar' => [Request::class, '__construct', 'headers'];
        yield 'Request gövde' => [Request::class, '__construct', 'body'];
        yield 'Response gövde' => [Response::class, '__construct', 'body'];
        yield 'ApiResponse data' => [ApiResponse::class, '__construct', 'data'];
        yield 'Consent alıcı' => [Consent::class, '__construct', 'recipient'];
        yield 'Consent fromArray' => [Consent::class, 'fromArray', 'data'];
        yield 'ConsentQuery alıcı' => [ConsentQuery::class, '__construct', 'recipient'];
        yield 'ConsentQuery fromArray' => [ConsentQuery::class, 'fromArray', 'data'];
        yield 'ConsentRecord alıcı' => [ConsentRecord::class, '__construct', 'recipient'];
        yield 'ConsentRecord fromArray' => [ConsentRecord::class, 'fromArray', 'data'];
        yield 'Validate alıcı' => [Validate::class, 'recipient', 'recipient'];
        yield 'Mask alıcı' => [Mask::class, 'recipient', 'recipient'];
    }

    /**
     * Öznitelik ad alanında `\` olmadan yazılırsa `Netgsm\Iys\...\SensitiveParameter` olarak çözülür ve
     * sessizce etkisiz kalır; bu test tam adı doğrular.
     */
    #[DataProvider('sensitiveParameters')]
    public function testParameterIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        foreach ($reflection->getParameters() as $param) {
            if ($param->getName() === $parameter) {
                self::assertCount(1, $param->getAttributes(\SensitiveParameter::class), "$class::$method(\$$parameter)");

                return;
            }
        }

        self::fail("$class::$method(\$$parameter) parametresi bulunamadı.");
    }

    private function clientWith(\Closure $handler): IysClient
    {
        $transport = new class ($handler) implements Transport {
            public function __construct(private readonly \Closure $handler)
            {
            }

            public function send(Request $request): Response
            {
                return ($this->handler)($request);
            }
        };

        return new IysClient($this->config(), $transport, new InMemoryTokenStore(), RetryPolicy::none());
    }

    private static function assertTraceIsClean(\Throwable $e): void
    {
        $trace = print_r($e->getTrace(), true) . $e->getTraceAsString() . $e->getMessage();

        foreach ([self::DIGITS, self::PASSWORD, 'secret-access', 'secret-refresh'] as $secret) {
            self::assertStringNotContainsString($secret, $trace, "Yığında açık değer: $secret");
        }
    }
}
