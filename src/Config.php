<?php

declare(strict_types=1);

namespace Netgsm\Iys;

use Netgsm\Iys\Enum\IysPath;
use Netgsm\Iys\Exception\ConfigurationException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Support\Validate;

/**
 * Netgsm hesap bilgileri ve İYS marka kodu.
 *
 * Şifre dökümlerde (var_dump, print_r, hata izleri) görünmez.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://ext-api.netgsm.com.tr';

    public const ENV_USERNAME = 'NETGSM_USERCODE';
    public const ENV_PASSWORD = 'NETGSM_PASSWORD';
    public const ENV_BRAND_CODE = 'NETGSM_BRANDCODE';

    public readonly string $baseUrl;

    /**
     * @param string       $username  Netgsm abone numarası.
     * @param string       $password  API alt kullanıcısının şifresi.
     * @param int          $brandCode İYS marka kodu (İYS kodu değil).
     * @param IysPath|null $path      Gönderilmezse API `SMSAPI` kabul eder.
     * @param string       $baseUrl   Yalnızca HTTPS.
     */
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        public readonly int $brandCode,
        public readonly ?IysPath $path = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if (trim($username) === '') {
            throw new ConfigurationException('Netgsm kullanıcı adı (abone numarası) boş olamaz.');
        }
        if ($password === '') {
            throw new ConfigurationException('Netgsm şifresi boş olamaz.');
        }
        // Geçersiz UTF-8, token isteğinin JSON gövdesi oluşturulurken hataya yol açar.
        if (!Validate::isUtf8($username) || !Validate::isUtf8($password)) {
            throw new ConfigurationException('Netgsm kullanıcı adı ve şifresi geçerli UTF-8 olmalıdır (.env dosyasının kodlamasını kontrol edin).');
        }

        try {
            Validate::brandCode($brandCode);
        } catch (InvalidArgumentException $e) {
            throw new ConfigurationException($e->getMessage(), 0, $e);
        }

        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (
            !str_starts_with(strtolower($baseUrl), 'https://')
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['host'] ?? '') === ''
            // `https://ext-api.netgsm.com.tr@baska-host` gibi adresler kimlik bilgisini başka hosta gönderir.
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new ConfigurationException('baseUrl kullanıcı bilgisi, sorgu ya da # içermeyen geçerli bir HTTPS adresi olmalıdır.');
        }
        $this->baseUrl = $baseUrl;
    }

    /**
     * `NETGSM_USERCODE`, `NETGSM_PASSWORD` ve `NETGSM_BRANDCODE` değişkenlerinden okur.
     * Sırasıyla `$_ENV`, `$_SERVER` ve `getenv()` denenir.
     */
    public static function fromEnv(?IysPath $path = null): self
    {
        $username = self::env(self::ENV_USERNAME);
        $password = self::env(self::ENV_PASSWORD);
        $brandCode = self::env(self::ENV_BRAND_CODE);

        $missing = [];
        foreach ([self::ENV_USERNAME => $username, self::ENV_PASSWORD => $password, self::ENV_BRAND_CODE => $brandCode] as $name => $value) {
            if ($value === null || $value === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new ConfigurationException('Ortam değişkeni tanımlı değil: ' . implode(', ', $missing));
        }

        /** @var string $username */
        /** @var string $password */
        /** @var string $brandCode */
        if (!ctype_digit($brandCode)) {
            throw new ConfigurationException(self::ENV_BRAND_CODE . ' yalnızca rakamlardan oluşmalıdır.');
        }

        return new self($username, $password, (int) $brandCode, $path);
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'password' => '***',
            'brandCode' => $this->brandCode,
            'path' => $this->path,
            'baseUrl' => $this->baseUrl,
        ];
    }

    /**
     * Serileştirilmiş hali şifreyi açık metin olarak içerir (kuyruk, önbellek, oturum). Kuyruk işlerinde
     * istemciyi işin içinde {@see self::fromEnv()} ile oluşturun.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Config serileştirilemez: şifre açık metin olarak yazılır. İstemciyi kullanıldığı yerde oluşturun.');
    }

    /**
     * Elle hazırlanmış bir serileştirme verisi kurucudaki doğrulamaları (HTTPS, marka kodu) atlayamaz.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Config serileştirilmiş veriden oluşturulamaz.');
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : null;
    }
}
