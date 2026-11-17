<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Unit;

use Netgsm\Iys\Config;
use Netgsm\Iys\Enum\IysPath;
use Netgsm\Iys\Exception\ConfigurationException;

final class ConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Config::ENV_USERNAME, Config::ENV_PASSWORD, Config::ENV_BRAND_CODE] as $name) {
            $this->envBackup[$name] = getenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testFromEnvReadsLegacyVariableNames(): void
    {
        $_ENV[Config::ENV_USERNAME] = '8500000000';
        $_ENV[Config::ENV_PASSWORD] = self::PASSWORD;
        putenv(Config::ENV_BRAND_CODE . '=654321');

        $config = Config::fromEnv(IysPath::SMSAPI);

        self::assertSame('8500000000', $config->username);
        self::assertSame(self::PASSWORD, $config->password());
        self::assertSame(654321, $config->brandCode);
        self::assertSame(IysPath::SMSAPI, $config->path);
        self::assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl);
    }

    public function testFromEnvListsMissingVariablesInsteadOfSendingPlaceholders(): void
    {
        $_ENV[Config::ENV_USERNAME] = '8500000000';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NETGSM_PASSWORD, NETGSM_BRANDCODE');

        Config::fromEnv();
    }

    public function testFromEnvRejectsNonNumericBrandCode(): void
    {
        $_ENV[Config::ENV_USERNAME] = '8500000000';
        $_ENV[Config::ENV_PASSWORD] = self::PASSWORD;
        $_ENV[Config::ENV_BRAND_CODE] = 'x';

        $this->expectException(ConfigurationException::class);
        Config::fromEnv();
    }

    public function testRejectsEmptyCredentials(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config('8500000000', '', self::BRAND_CODE);
    }

    public function testRejectsOutOfRangeBrandCode(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config('8500000000', self::PASSWORD, 0);
    }

    public function testRejectsPlainHttpBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config('8500000000', self::PASSWORD, self::BRAND_CODE, null, 'http://ext-api.netgsm.com.tr');
    }

    public function testTrailingSlashIsRemovedFromBaseUrl(): void
    {
        $config = new Config('8500000000', self::PASSWORD, self::BRAND_CODE, null, 'https://ext-api.netgsm.com.tr/');

        self::assertSame('https://ext-api.netgsm.com.tr', $config->baseUrl);
    }

    public function testPasswordIsHiddenInDumps(): void
    {
        $dump = print_r($this->config(), true);

        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertStringContainsString('***', $dump);
    }
}
