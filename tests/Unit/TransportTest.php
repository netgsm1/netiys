<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Exception\ConfigurationException;
use Netgsm\Iys\Http\CurlTransport;
use Netgsm\Iys\Http\Request;
use Netgsm\Iys\RetryPolicy;

final class TransportTest extends TestCase
{
    public function testCurlTransportRefusesPlainHttp(): void
    {
        $this->expectException(ConfigurationException::class);
        (new CurlTransport())->send(new Request('GET', 'http://ext-api.netgsm.com.tr/iys/v1/consents'));
    }

    public function testCurlTransportHasNoOptionToDisableTlsVerification(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/CurlTransport.php');

        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $source);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST => 2', $source);
        self::assertStringNotContainsString('VERIFYPEER => false', $source);
    }

    public function testRequestDumpHidesAuthorizationAndBody(): void
    {
        $dump = print_r(new Request('POST', 'https://example.com', ['Authorization' => 'Bearer secret-token'], '{"password":"secret"}'), true);

        self::assertStringNotContainsString('secret', $dump);
    }

    public function testRetryPolicyBackoff(): void
    {
        $policy = new RetryPolicy(3, 1000, 5000);

        self::assertSame(1000, $policy->delayMs(0));
        self::assertSame(2000, $policy->delayMs(1));
        self::assertSame(4000, $policy->delayMs(2));
        self::assertSame(5000, $policy->delayMs(5));
        self::assertSame(3000, $policy->delayMs(0, '3'));
        self::assertSame(5000, $policy->delayMs(0, '120'), 'Retry-After üst sınırı aşamaz.');
        self::assertTrue($policy->canRetry(2));
        self::assertFalse($policy->canRetry(3));
        self::assertFalse(RetryPolicy::none()->canRetry(0));
    }
}
