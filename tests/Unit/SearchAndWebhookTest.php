<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\ApiException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Tests\Fake\FakeTransport;

final class SearchAndWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->transport->queue(FakeTransport::TOKEN_PATH, FakeTransport::token());
    }

    private function query(string $recipient = self::PHONE, ConsentType $type = ConsentType::MESAJ, ?string $refId = null): ConsentQuery
    {
        return new ConsentQuery($recipient, RecipientType::BIREYSEL, $type, $refId);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function record(string $recipient, array $overrides = []): array
    {
        return $overrides + [
            'type' => 'MESAJ', 'source' => 'HS_WEB', 'recipient' => $recipient, 'status' => 'ONAY',
            'consentDate' => '2026-01-01 10:00:00', 'recipientType' => 'BIREYSEL', 'creationDate' => null,
            'queryStatus' => null, 'retailerAccessCount' => null, 'errorCode' => 0, 'message' => null,
        ];
    }

    public function testBuildsEncodedDeepObjectQuery(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, [
            self::record(self::PHONE),
            self::record(self::EMAIL, ['type' => 'EPOSTA', 'status' => null, 'source' => null, 'errorCode' => 81, 'message' => 'Resource not found']),
        ]));

        $records = $this->client()->searchConsents(
            [$this->query(), $this->query(self::EMAIL, ConsentType::EPOSTA, 'kayit-1')],
            'istek-ref',
        );

        $request = $this->transport->requestsTo(FakeTransport::CONSENTS_PATH)[0];
        self::assertSame('GET', $request->method);
        self::assertNull($request->body);
        self::assertNull($request->header('Content-Type'));

        $query = (string) parse_url($request->url, PHP_URL_QUERY);
        self::assertStringContainsString('brandCode=123456', $query);
        self::assertStringContainsString('refId=istek-ref', $query);
        self::assertStringContainsString('data%5B0%5D%5Brecipient%5D=%2B905000000001', $query, '+ işareti %2B olarak kodlanmalı.');
        self::assertStringContainsString('data%5B1%5D%5Btype%5D=EPOSTA', $query);
        self::assertStringContainsString('data%5B1%5D%5BrefId%5D=kayit-1', $query);

        parse_str($query, $parsed);
        self::assertIsArray($parsed['data']);
        self::assertSame(self::PHONE, $parsed['data'][0]['recipient']);

        self::assertCount(2, $records);
        self::assertTrue($records[0]->isApproved());
        self::assertSame(ConsentStatus::ONAY, $records[0]->status);
        self::assertTrue($records[1]->isNotFound());
        self::assertFalse($records[1]->isApproved());
    }

    public function testLargeListsAreChunkedInOrder(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(200, 0, [self::record('+905000000001'), self::record('+905000000002')]),
            FakeTransport::api(200, 0, [self::record('+905000000003', ['status' => 'RET'])]),
        );

        $records = $this->client()->searchConsents([
            $this->query('+905000000001'),
            $this->query('+905000000002'),
            $this->query('+905000000003'),
        ], null, 2);

        self::assertCount(2, $this->transport->requestsTo(FakeTransport::CONSENTS_PATH));
        self::assertSame(['+905000000001', '+905000000002', '+905000000003'], array_map(static fn ($r) => $r->recipient, $records));
        self::assertTrue($records[2]->isRejected());
    }

    public function testSingleQueryNotFoundBecomesRecord(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(404, 81, [], 'Resource not found'));

        $record = $this->client()->searchConsent($this->query());

        self::assertTrue($record->isNotFound());
        self::assertFalse($record->isApproved());
        self::assertSame(self::PHONE, $record->recipient);
    }

    public function testReadErrorsAreRetried(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(424, 112, [], 'Api Error'),
            new TransportException('timeout', true),
            FakeTransport::api(200, 0, [self::record(self::PHONE)]),
        );

        self::assertTrue($this->client()->searchConsent($this->query())->isApproved());
        self::assertSame([1000, 2000], $this->sleeps);
    }

    public function testReadErrorsGiveUpAfterMaxRetries(): void
    {
        $this->transport->queue(
            FakeTransport::CONSENTS_PATH,
            FakeTransport::api(500, 110, [], 'Server Error'),
            FakeTransport::api(500, 110, [], 'Server Error'),
            FakeTransport::api(500, 110, [], 'Server Error'),
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(110);
        $this->client()->searchConsent($this->query());
    }

    public function testMismatchedResultCountIsRejected(): void
    {
        $this->transport->queue(FakeTransport::CONSENTS_PATH, FakeTransport::api(200, 0, [self::record(self::PHONE)]));

        $this->expectException(ApiException::class);
        $this->client()->searchConsents([$this->query(), $this->query(self::PHONE_2)]);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->searchConsents([$this->query()], null, 1001);
    }

    public function testSaveWebhook(): void
    {
        $this->transport->queue(FakeTransport::WEBHOOKS_PATH, FakeTransport::api(200, 0, ['uid' => 'wh-1']));

        $result = $this->client()->saveWebhook('https://example.com/iys/webhook/s3cr3t');

        $request = $this->transport->requestsTo(FakeTransport::WEBHOOKS_PATH)[0];
        self::assertSame('POST', $request->method);
        self::assertSame(
            ['brandCode' => self::BRAND_CODE, 'webhookUrl' => 'https://example.com/iys/webhook/s3cr3t'],
            self::decodeBody($request->body),
        );
        self::assertSame('wh-1', $result->uid);
    }

    public function testWebhookServerErrorIsRetriedBecauseItIsIdempotent(): void
    {
        $this->transport->queue(
            FakeTransport::WEBHOOKS_PATH,
            new Response(502, [], 'Bad Gateway'),
            FakeTransport::api(200, 0, ['uid' => 'wh-1']),
        );

        self::assertSame('wh-1', $this->client()->saveWebhook('https://example.com/hook')->uid);
    }

    public function testWebhookRequiresHttps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->saveWebhook('http://example.com/hook');
    }
}
