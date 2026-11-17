<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Auth\InMemoryTokenStore;
use Netgsm\Iys\Auth\Token;
use Netgsm\Iys\Auth\TokenProvider;
use Netgsm\Iys\Auth\TokenStore;
use Netgsm\Iys\Config;
use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Dto\ConsentRecord;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\AmbiguousResultException;
use Netgsm\Iys\Exception\ApiException;
use Netgsm\Iys\Exception\AuthenticationException;
use Netgsm\Iys\Exception\ConfigurationException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Tests\Fake\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * İkinci güvenlik incelemesinin bulguları (3.0.2).
 */
final class HardeningTest extends TestCase
{
    private const DIGITS = '905000000001';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    // --- POST /consents yeniden gönderimi ---

    public function testEnvelopeless503OnSaveIsAmbiguousAndNotResent(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, new Response(503, ['Content-Type' => 'text/plain'], 'upstream connect error or disconnect/reset before headers'));

        try {
            $this->client()->addConsent($this->consent());
            self::fail('AmbiguousResultException bekleniyordu.');
        } catch (AmbiguousResultException) {
            self::assertCount(1, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH));
        }
    }

    public function testEnvelopeless503OnQueryIsRetried(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(
                FakeTransport::CONSENTS_PATH,
                new Response(503, [], 'upstream reset'),
                FakeTransport::api(200, 0, [['recipient' => self::PHONE, 'status' => 'ONAY', 'errorCode' => 0]]),
            );

        $record = $this->client()->searchConsent($this->query());

        self::assertTrue($record->isApproved());
        self::assertCount(2, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH));
    }

    public function testEnvelopeless429OnSaveIsStillRetried(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, new Response(429, [], 'Too Many Requests'), FakeTransport::api(200, 0, ['uid' => 'u']));

        self::assertSame('u', $this->client()->addConsent($this->consent())->uid);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableRejections(): iterable
    {
        yield 'erroritem nesne' => [['uid' => 'u', 'erroritem' => ['index' => 0]]];
        yield 'erroritem metin' => [['uid' => 'u', 'erroritem' => 'hata']];
        yield 'öğe dizi değil' => [['uid' => 'u', 'erroritem' => ['hata']]];
        yield 'index yok' => [['uid' => 'u', 'erroritem' => [['field' => 'recipient']]]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('unreadableRejections')]
    public function testUnreadableRejectionsAreAmbiguous(array $data): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, $data));

        $this->expectException(AmbiguousResultException::class);
        $this->client()->addConsent($this->consent());
    }

    // --- Sorgu sonucunun doğruluğu ---

    public function testSearchRejectsResultForAnotherRecipient(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, [
                ['recipient' => self::PHONE_2, 'status' => 'ONAY', 'errorCode' => 0],
                ['recipient' => self::PHONE, 'status' => 'RET', 'errorCode' => 0],
            ]));

        $this->expectException(ApiException::class);
        $this->client()->searchConsents([$this->query(), $this->query(self::PHONE_2)]);
    }

    public function testSearchAcceptsRecipientWithoutPlusOrDifferentCase(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, [
                ['recipient' => self::DIGITS, 'status' => 'ONAY', 'errorCode' => 0],
                ['recipient' => strtoupper(self::EMAIL), 'status' => 'RET', 'errorCode' => 0],
            ]));

        $records = $this->client()->searchConsents([$this->query(), $this->query(self::EMAIL, ConsentType::EPOSTA)]);

        self::assertTrue($records[0]->isApproved());
        self::assertTrue($records[1]->isRejected());
    }

    public function testNonNumericErrorCodeFailsClosed(): void
    {
        $record = ConsentRecord::fromArray(['status' => 'ONAY', 'errorCode' => 'abc'], $this->query());

        self::assertFalse($record->isApproved());
        self::assertTrue($record->isUnknown());
    }

    // --- json_encode ve döküm maskeleme ---

    public function testDtoJsonEncodeIsMasked(): void
    {
        $record = ConsentRecord::fromArray(['recipient' => self::PHONE, 'status' => 'ONAY', 'errorCode' => 0], $this->query());

        $json = json_encode([$this->consent(), $this->query(self::EMAIL, ConsentType::EPOSTA), $record], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::DIGITS, $json);
        self::assertStringNotContainsString(self::EMAIL, $json);
        self::assertStringNotContainsString('raw', $json);
        self::assertSame(self::PHONE, $this->consent()->toArray()['recipient'], 'API gövdesi değişmez.');
    }

    public function testApiExceptionDumpHidesData(): void
    {
        $e = new ApiException('hata', 422, 70, 'Validation', ['errors' => [['recipient' => self::PHONE]]]);

        $dump = print_r($e, true);

        self::assertStringNotContainsString(self::DIGITS, $dump);
        self::assertStringContainsString('*** (1 öğe)', $dump);
        self::assertSame(self::PHONE, $e->data['errors'][0]['recipient'], 'Veriye erişim değişmez.');
    }

    public function testResponseDumpHidesCookies(): void
    {
        $dump = print_r(new Response(200, ['Set-Cookie' => 'session=secret-cookie']), true);

        self::assertStringNotContainsString('secret-cookie', $dump);
    }

    // --- Geçersiz UTF-8 ---

    public function testNonUtf8PasswordIsRejectedWithoutLeaking(): void
    {
        $password = "\xDE" . 'ifre-Gizli123'; // Windows-1254 "Ş"

        try {
            new Config('8500000000', $password, self::BRAND_CODE);
            self::fail('ConfigurationException bekleniyordu.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('ifre-Gizli123', print_r($e->getTrace(), true) . $e->getMessage());
        }
    }

    public function testNonUtf8RefIdIsRejectedBeforeJsonEncoding(): void
    {
        try {
            $this->client()->addConsent($this->consent(), "kayit-\xFF");
            self::fail('InvalidArgumentException bekleniyordu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::DIGITS, print_r($e->getTrace(), true));
            self::assertSame([], $this->transport->requests, 'İstek gönderilmemeli.');
        }
    }

    // --- Token ---

    public function testCacheKeyIsDerivedWithPbkdf2(): void
    {
        $store = new class () implements TokenStore {
            /** @var list<string> */
            public array $keys = [];

            public function get(string $key): ?Token
            {
                $this->keys[] = $key;

                return null;
            }

            public function save(string $key, Token $token): void
            {
                $this->keys[] = $key;
            }

            public function delete(string $key): void
            {
            }
        };
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::WEBHOOKS_PATH, FakeTransport::api(200, 0, ['uid' => 'w']));

        $this->client($store)->saveWebhook('https://example.com/iys');

        $config = $this->config();
        $salt = 'netgsm-iys-token-v1' . "\0" . $config->baseUrl . "\0" . $config->username;
        $expected = 'netgsm-iys-token-' . hash_pbkdf2('sha256', self::PASSWORD, $salt, TokenProvider::KEY_DERIVATION_ITERATIONS, 64);

        self::assertSame([$expected, $expected], $store->keys);
        self::assertNotSame(
            'netgsm-iys-token-' . hash_hmac('sha256', $config->baseUrl . "\0" . $config->username, self::PASSWORD),
            $expected,
        );
    }

    public function testHugeExpiresInIsCapped(): void
    {
        $store = new InMemoryTokenStore();
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(200, ['access_token' => 'a', 'expires_in' => 1e30, 'refresh_token' => 'r']))
            ->queue(FakeTransport::WEBHOOKS_PATH, FakeTransport::api(200, 0, ['uid' => 'w']));

        $this->client($store)->saveWebhook('https://example.com/iys');

        $token = $this->transport->requestsTo(FakeTransport::WEBHOOKS_PATH)[0]->header('Authorization');
        self::assertSame('Bearer a', $token);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExpiresIn(): iterable
    {
        yield 'negatif' => [-5];
        yield 'sıfır' => [0];
        yield 'metin' => ['yarın'];
    }

    #[DataProvider('invalidExpiresIn')]
    public function testInvalidExpiresInIsRejected(mixed $expiresIn): void
    {
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(200, ['access_token' => 'a', 'expires_in' => $expiresIn]));

        $this->expectException(AuthenticationException::class);
        $this->client(maxRetries: 0)->saveWebhook('https://example.com/iys');
    }

    // --- Config ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'kullanıcı bilgisi' => ['https://ext-api.netgsm.com.tr@127.0.0.1'];
        yield 'kullanıcı ve şifre' => ['https://a:b@ext-api.netgsm.com.tr'];
        yield 'sorgu' => ['https://ext-api.netgsm.com.tr?x=1'];
        yield 'parça' => ['https://ext-api.netgsm.com.tr#x'];
        yield 'http' => ['http://ext-api.netgsm.com.tr'];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function testUnsafeBaseUrlIsRejected(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);
        new Config('8500000000', self::PASSWORD, self::BRAND_CODE, null, $baseUrl);
    }

    public function testBaseUrlWithPathIsAccepted(): void
    {
        self::assertSame('https://proxy.example.com/netgsm', (new Config('8500000000', self::PASSWORD, self::BRAND_CODE, null, 'https://proxy.example.com/netgsm/'))->baseUrl);
    }

    public function testCraftedSerializedConfigIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        unserialize('O:17:"Netgsm\Iys\Config":1:{s:7:"baseUrl";s:21:"http://127.0.0.1:8080";}');
    }

    public function testWebhookUrlIsNotWrittenToStackTrace(): void
    {
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(401, ['code' => 'invalid_credentials']));

        try {
            $this->client(maxRetries: 0)->saveWebhook('https://example.com/iys/webhook/gizli-yol-123');
            self::fail('AuthenticationException bekleniyordu.');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString('gizli-yol-123', print_r($e->getTrace(), true));
        }
    }

    private function query(string $recipient = self::PHONE, ConsentType $type = ConsentType::MESAJ): ConsentQuery
    {
        return new ConsentQuery($recipient, RecipientType::BIREYSEL, $type);
    }
}
