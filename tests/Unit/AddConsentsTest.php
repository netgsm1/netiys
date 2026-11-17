<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Config;
use Netgsm\Iys\Enum\IysPath;
use Netgsm\Iys\Exception\AmbiguousResultException;
use Netgsm\Iys\Exception\ApiException;
use Netgsm\Iys\Exception\AuthenticationException;
use Netgsm\Iys\Exception\ForbiddenException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Exception\ValidationException;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Tests\Fake\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class AddConsentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::token(), FakeTransport::token('access-2'));
    }

    public function testSendsContractShapedRequest(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, ['uid' => 'uid-1']));

        $config = new Config('8500000000', self::PASSWORD, self::BRAND_CODE, IysPath::SMSAPI);
        $result = $this->client(config: $config)->addConsents([$this->consent(), $this->consent(self::EMAIL)], 'musteri-4711', 'my-request-0001');

        $request = $this->transport->requestsTo(FakeTransport::CONSENTS_PATH)[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://ext-api.netgsm.com.tr/iys/v1/consents', $request->url);
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('my-request-0001', $request->header('X-Request-Id'));

        $body = self::decodeBody($request->body);
        self::assertSame(self::BRAND_CODE, $body['brandCode']);
        self::assertSame('SMSAPI', $body['path']);
        self::assertSame('musteri-4711', $body['refId']);
        self::assertIsArray($body['permissions']);
        self::assertCount(2, $body['permissions']);
        self::assertSame(self::PHONE, $body['permissions'][0]['recipient']);
        self::assertSame(self::EMAIL, $body['permissions'][1]['recipient']);

        self::assertSame('uid-1', $result->uid);
        self::assertFalse($result->hasRejections());
        self::assertSame('my-request-0001', $result->requestId);
        self::assertSame('thread-test', $result->threadId);
    }

    public function testGeneratesValidRequestIdWhenNotGiven(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, ['uid' => 'u']));

        $this->client()->addConsent($this->consent());

        $id = (string) $this->transport->requestsTo(FakeTransport::CONSENTS_PATH)[0]->header('X-Request-Id');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', $id);
    }

    public function testRejectsInvalidRequestId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->addConsent($this->consent(), null, 'kısa');
    }

    public function testPartialRejectionIsReturned(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, [
            'uid' => 'uid-1',
            'erroritem' => [['index' => 1, 'field' => 'recipient', 'message' => 'Geçersiz telefon numarası formatı']],
        ]));

        $sent = [$this->consent(), $this->consent(self::PHONE_2)];
        $result = $this->client()->addConsents($sent);

        self::assertTrue($result->hasRejections());
        self::assertSame(1, $result->rejected[0]->index);
        self::assertSame('recipient', $result->rejected[0]->field);
        self::assertSame($sent[1], $result->rejectedWith($sent)[0]['consent']);
    }

    public function testRejectsMoreThan500Consents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->addConsents(array_fill(0, 501, $this->consent()));
    }

    public function testRejectsEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->addConsents([]);
    }

    public function testUnauthorizedTriggersSingleReloginAndRetry(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(401, 53, [], 'Token has expired'),
            FakeTransport::api(200, 0, ['uid' => 'u']),
        );

        $this->client()->addConsent($this->consent());

        $requests = $this->transport->requestsTo(FakeTransport::CONSENTS_PATH);
        self::assertCount(2, $requests);
        self::assertSame('Bearer access-1', $requests[0]->header('Authorization'));
        self::assertSame('Bearer access-2', $requests[1]->header('Authorization'));
        self::assertSame($requests[0]->header('X-Request-Id'), $requests[1]->header('X-Request-Id'));
    }

    public function testSecondUnauthorizedDoesNotLoop(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(401, 52, [], 'Invalid token'),
            FakeTransport::api(401, 52, [], 'Invalid token'),
        );

        $this->expectException(AuthenticationException::class);
        try {
            $this->client()->addConsent($this->consent());
        } finally {
            self::assertCount(2, $this->transport->requestsTo(FakeTransport::TOKEN_PATH));
        }
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function retryableResponses(): iterable
    {
        yield '503 / 141' => [FakeTransport::api(503, 141, [], 'Integration error')];
        yield '429 / 150' => [FakeTransport::api(429, 150, [], 'Too many requests.')];
    }

    #[DataProvider('retryableResponses')]
    public function testUnprocessedErrorsAreRetriedWithBackoff(Response $response): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, $response, $response, FakeTransport::api(200, 0, ['uid' => 'u']));

        $result = $this->client()->addConsent($this->consent());

        self::assertSame('u', $result->uid);
        self::assertSame([1000, 2000], $this->sleeps);
    }

    public function testRetryAfterHeaderIsHonoured(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(429, 150, [], 'Too many requests.', ['Retry-After' => '7']),
            FakeTransport::api(200, 0),
        );

        $this->client()->addConsent($this->consent());

        self::assertSame([7000], $this->sleeps);
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function ambiguousResponses(): iterable
    {
        yield '424 / 112' => [FakeTransport::api(424, 112, [], 'Api Error')];
        yield '500 / 110' => [FakeTransport::api(500, 110, [], 'Server Error')];
        yield '502 HTML' => [new Response(502, [], '<html>Bad Gateway</html>')];
        yield '200 bozuk gövde' => [new Response(200, [], 'not-json')];
    }

    #[DataProvider('ambiguousResponses')]
    public function testAmbiguousResultsAreNeverRetried(Response $response): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, $response);

        try {
            $this->client()->addConsent($this->consent(), null, 'ambiguous-0001');
            self::fail('AmbiguousResultException bekleniyordu.');
        } catch (AmbiguousResultException $e) {
            self::assertSame('ambiguous-0001', $e->requestId);
        }

        self::assertCount(1, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH));
        self::assertSame([], $this->sleeps);
    }

    public function testTransportErrorAfterSendIsAmbiguous(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, new TransportException('timeout', true));

        $this->expectException(AmbiguousResultException::class);
        try {
            $this->client()->addConsent($this->consent());
        } finally {
            self::assertCount(1, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH));
        }
    }

    public function testConnectionFailureBeforeSendIsRetried(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            new TransportException('could not connect', false),
            FakeTransport::api(200, 0, ['uid' => 'u']),
        );

        self::assertSame('u', $this->client()->addConsent($this->consent())->uid);
    }

    public function testValidationErrorExposesFieldErrorsAndIsNotRetried(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(422, 70, ['errors' => ['brandCode' => 'The brand code field is required.']], 'Validation failed'),
        );

        try {
            $this->client()->addConsent($this->consent());
            self::fail('ValidationException bekleniyordu.');
        } catch (ValidationException $e) {
            self::assertSame(70, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertSame(['brandCode' => 'The brand code field is required.'], $e->errors());
            self::assertSame('thread-test', $e->threadId);
        }
        self::assertSame([], $this->sleeps);
    }

    public function testInactiveIysModuleIsForbidden(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(403, 63, [], 'IYS module inactive'));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(63);
        $this->client()->addConsent($this->consent());
    }

    public function testConflictIsPlainApiException(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(409, 130, [], 'Conflict'));

        try {
            $this->client()->addConsent($this->consent());
            self::fail('ApiException bekleniyordu.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(130, $e->errorCode);
        }
    }
}
