<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Dto\ConsentRecord;
use Netgsm\Iys\Enum\ConsentSource;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Support\Mask;
use PHPUnit\Framework\Attributes\DataProvider;

final class DtoTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRecipients(): iterable
    {
        yield 'başında + yok (SMS API biçimi)' => ['905000000001'];
        yield 'yerel biçim' => ['05000000001'];
        yield 'eksik hane' => ['+90500000000'];
        yield 'boşluklu' => ['+90 500 000 00 01'];
        yield 'geçersiz e-posta' => ['test@'];
        yield 'boş' => [''];
    }

    #[DataProvider('invalidRecipients')]
    public function testRejectsInvalidRecipient(string $recipient): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->consent($recipient);
    }

    public function testInvalidRecipientMessageDoesNotLeakTheValue(): void
    {
        try {
            $this->consent('905000000001');
            self::fail('Exception bekleniyordu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('905000000001', $e->getMessage());
        }
    }

    public function testAcceptsEmailRecipient(): void
    {
        self::assertSame(self::EMAIL, $this->consent(self::EMAIL)->recipient);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'ISO 8601' => ['2026-02-20T16:46:00'];
        yield 'saniyesiz' => ['2026-02-20 16:46'];
        yield 'takvimde yok' => ['2026-02-30 10:00:00'];
        yield 'saat taşması' => ['2026-02-20 25:00:00'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidConsentDate(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Consent(self::PHONE, RecipientType::BIREYSEL, ConsentType::MESAJ, ConsentSource::HS_WEB, ConsentStatus::ONAY, $date);
    }

    public function testFormatsDateTimeObjects(): void
    {
        $consent = new Consent(
            self::PHONE,
            RecipientType::TACIR,
            ConsentType::ARAMA,
            ConsentSource::HS_FIZIKSEL_ORTAM,
            ConsentStatus::RET,
            new \DateTimeImmutable('2026-01-02 03:04:05'),
        );

        self::assertSame('2026-01-02 03:04:05', $consent->consentDate);
    }

    public function testToArrayOmitsOptionalRetailerFields(): void
    {
        self::assertSame([
            'recipient' => self::PHONE,
            'recipientType' => 'BIREYSEL',
            'type' => 'MESAJ',
            'source' => 'HS_WEB',
            'status' => 'ONAY',
            'consentDate' => '2026-02-20 16:46:00',
        ], $this->consent()->toArray());
    }

    public function testFromArrayAcceptsV2StyleArrays(): void
    {
        $consent = Consent::fromArray([
            'type' => 'MESAJ',
            'source' => 'HS_WEB',
            'recipient' => self::PHONE,
            'recipientType' => 'BIREYSEL',
            'status' => 'ONAY',
            'consentDate' => '2026-02-20 16:46:00',
            'retailerCode' => 0,
            'retailerAccess' => [1, 2],
        ]);

        self::assertSame(ConsentSource::HS_WEB, $consent->source);
        self::assertSame(0, $consent->toArray()['retailerCode']);
        self::assertSame([1, 2], $consent->toArray()['retailerAccess']);
    }

    public function testFromArrayRejectsUnknownEnumValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source şu değerlerden biri olmalıdır');

        Consent::fromArray([
            'type' => 'MESAJ',
            'source' => 'WEB',
            'recipient' => self::PHONE,
            'recipientType' => 'BIREYSEL',
            'status' => 'ONAY',
            'consentDate' => '2026-02-20 16:46:00',
        ]);
    }

    public function testFromArrayRequiresAllFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('status alanı zorunludur');

        Consent::fromArray(['type' => 'MESAJ', 'source' => 'HS_WEB', 'recipient' => self::PHONE, 'recipientType' => 'BIREYSEL', 'consentDate' => '2026-02-20 16:46:00']);
    }

    public function testRecipientIsMaskedInDumps(): void
    {
        $dump = print_r($this->consent(), true);

        self::assertStringNotContainsString(self::PHONE, $dump);
        self::assertStringContainsString(Mask::recipient(self::PHONE), $dump);
    }

    public function testMask(): void
    {
        self::assertSame('+90500*****01', Mask::recipient(self::PHONE));
        self::assertSame('t****@example.com', Mask::recipient(self::EMAIL));
    }

    public function testConsentRecordDecision(): void
    {
        $query = new ConsentQuery(self::PHONE, RecipientType::BIREYSEL, ConsentType::MESAJ);

        $approved = ConsentRecord::fromArray(['status' => 'ONAY', 'errorCode' => 0], $query);
        $rejected = ConsentRecord::fromArray(['status' => 'RET', 'errorCode' => 0], $query);
        $missing = ConsentRecord::fromArray(['status' => null, 'errorCode' => 81], $query);
        $unknown = ConsentRecord::fromArray(['status' => 'ONAY', 'errorCode' => 112], $query);

        self::assertTrue($approved->isApproved());
        self::assertFalse($rejected->isApproved());
        self::assertTrue($rejected->isRejected());
        self::assertFalse($missing->isApproved());
        self::assertTrue($missing->isNotFound());
        self::assertFalse($unknown->isApproved(), '112 alınan kayıtta izin varmış gibi davranılmaz.');
        self::assertTrue($unknown->isUnknown());
    }
}
