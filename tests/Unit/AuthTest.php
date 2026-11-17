<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Auth\InMemoryTokenStore;
use Netgsm\Iys\Auth\Token;
use Netgsm\Iys\Exception\AuthenticationException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Tests\Fake\FakeTransport;

final class AuthTest extends TestCase
{
    public function testTokenIsRequestedOnceWithSmsScopeAndReused(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, ['uid' => 'u1']), FakeTransport::api(200, 0, ['uid' => 'u2']));

        $client = $this->client();
        $client->addConsent($this->consent());
        $client->addConsent($this->consent());

        $tokenRequests = $this->transport->requestsTo(FakeTransport::TOKEN_PATH);
        self::assertCount(1, $tokenRequests);
        self::assertSame('https://ext-api.netgsm.com.tr/auth/v3/token', $tokenRequests[0]->url);
        self::assertSame(
            ['username' => '8500000000', 'password' => self::PASSWORD, 'scope' => 'sms'],
            self::decodeBody($tokenRequests[0]->body),
        );

        foreach ($this->transport->requestsTo(FakeTransport::CONSENTS_PATH) as $request) {
            self::assertSame('Bearer access-1', $request->header('Authorization'));
            self::assertStringNotContainsString(self::PASSWORD, (string) $request->body, 'Şifre İYS isteklerinde gönderilmez.');
        }
    }

    public function testExpiredTokenIsRefreshed(): void
    {
        $store = new InMemoryTokenStore();
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token('access-1', 'refresh-1', 30)) // leeway'den kısa
            ->queue(FakeTransport::REFRESH_PATH, FakeTransport::token('access-2', 'refresh-2'))
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, ['uid' => 'u1']), FakeTransport::api(200, 0, ['uid' => 'u2']));

        $client = $this->client($store);
        $client->addConsent($this->consent());
        $client->addConsent($this->consent());

        $refresh = $this->transport->requestsTo(FakeTransport::REFRESH_PATH);
        self::assertCount(1, $refresh);
        self::assertSame(['refresh_token' => 'refresh-1'], self::decodeBody($refresh[0]->body));

        $consents = $this->transport->requestsTo(FakeTransport::CONSENTS_PATH);
        self::assertSame('Bearer access-2', $consents[1]->header('Authorization'));
    }

    public function testFailedRefreshFallsBackToLogin(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::token('access-1', 'refresh-1', 30), FakeTransport::token('access-2'))
            ->queue(FakeTransport::REFRESH_PATH, FakeTransport::json(401, ['code' => 'invalid_refresh_token', 'status' => 401]))
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0), FakeTransport::api(200, 0));

        $client = $this->client();
        $client->addConsent($this->consent());
        $client->addConsent($this->consent());

        self::assertCount(2, $this->transport->requestsTo(FakeTransport::TOKEN_PATH));
        self::assertSame('Bearer access-2', $this->transport->requestsTo(FakeTransport::CONSENTS_PATH)[1]->header('Authorization'));
    }

    public function testIdentityErrorIsReportedWithoutLeakingThePassword(): void
    {
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(401, [
            'status' => 401,
            'code' => 'invalid_credentials',
            'detail' => 'Kullanıcı adı veya şifre hatalı.',
            'request_id' => 'req-123',
        ]));

        try {
            $this->client()->addConsent($this->consent());
            self::fail('AuthenticationException bekleniyordu.');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid_credentials', $e->identityCode());
            self::assertSame('req-123', $e->threadId);
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getTraceAsString());
        }

        self::assertCount(0, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH), 'Token yoksa izin isteği gönderilmez.');
    }

    public function testIdentityServerErrorIsRetried(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(503, ['code' => 'service_unavailable'], ['Retry-After' => '3']), FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0));

        $this->client()->addConsent($this->consent());

        self::assertSame([3000], $this->sleeps);
    }

    public function testTooManyFailedAttemptsIsNotRetried(): void
    {
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::json(429, ['code' => 'too_many_failed_attempts']));

        $this->expectException(AuthenticationException::class);
        try {
            $this->client()->addConsent($this->consent());
        } finally {
            self::assertSame([], $this->sleeps);
        }
    }

    public function testTokenRequestTransportErrorIsRetried(): void
    {
        $this->transport
            ->queue(FakeTransport::TOKEN_PATH, new TransportException('timeout', true), FakeTransport::token())
            ->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0));

        $this->client()->addConsent($this->consent());

        self::assertCount(2, $this->transport->requestsTo(FakeTransport::TOKEN_PATH));
    }

    public function testTokenSecretsAreHiddenInDumps(): void
    {
        $dump = print_r(new Token('very-secret-access', 'very-secret-refresh', time() + 100), true);

        self::assertStringNotContainsString('very-secret', $dump);
    }
}
