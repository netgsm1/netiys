<?php

declare(strict_types=1);

namespace Netgsm\Iys\Auth;

use Netgsm\Iys\Config;
use Netgsm\Iys\Exception\AuthenticationException;
use Netgsm\Iys\Exception\ConfigurationException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Http\Request;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Http\Transport;
use Netgsm\Iys\IysClient;
use Netgsm\Iys\RetryPolicy;

/**
 * Netgsm Identity API (`/auth/v3`) ile token alır, önbelleğe alır ve süresi dolmadan yeniler.
 *
 * Token `scope: "sms"` ile alınır; İYS API'si için Netgsm'in kuralı budur.
 * `POST /auth/v3/token` IP başına dakikada 30 istekle sınırlıdır; bu yüzden token her istekte alınmaz.
 *
 * @internal {@see IysClient} tarafından kullanılır.
 */
final class TokenProvider
{
    private const SCOPE = 'sms';

    /** Identity API'de tekrar denenebilecek durum kodları. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503];

    /** Önbellek anahtarı türetmede PBKDF2 tur sayısı; anahtar adından şifre tahminini yavaşlatır. */
    public const KEY_DERIVATION_ITERATIONS = 100_000;

    /** Sunucunun bildirdiği token ömrünün üst sınırı (saniye). */
    private const MAX_EXPIRES_IN = 30 * 86400;

    /**
     * Süreç içi anahtar önbelleği: PBKDF2 her süreçte hesap başına bir kez çalışır.
     *
     * @var array<string, string>
     */
    private static array $derivedKeys = [];

    private ?string $cacheKey = null;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly TokenStore $store,
        private readonly RetryPolicy $retryPolicy,
    ) {
    }

    public function accessToken(): string
    {
        $token = $this->store->get($this->cacheKey());
        if ($token !== null && $token->isUsable()) {
            return $token->accessToken();
        }

        if ($token !== null && $token->refreshToken() !== null) {
            try {
                return $this->refresh($token->refreshToken())->accessToken();
            } catch (AuthenticationException) {
                // Refresh token geçersiz ya da oturum kapanmış: yeniden giriş yapılır.
            }
        }

        return $this->login()->accessToken();
    }

    /**
     * Önbellekteki token'ı atar; sonraki {@see accessToken()} çağrısı yeniden giriş yapar.
     */
    public function invalidate(): void
    {
        $this->store->delete($this->cacheKey());
    }

    /**
     * Anahtar adres, kullanıcı adı ve şifreden yavaş bir KDF (PBKDF2-SHA256) ile türetilir:
     * - Paylaşılan bir depoda aynı kullanıcı adını yanlış şifreyle veren istemci başkasının token'ını kullanamaz.
     * - Depodaki anahtar adını gören biri şifreyi hızlı bir hash'e göre çok daha yavaş tahmin edebilir.
     */
    private function cacheKey(): string
    {
        if ($this->cacheKey !== null) {
            return $this->cacheKey;
        }

        $salt = 'netgsm-iys-token-v1' . "\0" . $this->config->baseUrl . "\0" . $this->config->username;
        $memo = hash('sha256', $salt . "\0" . $this->config->password());

        return $this->cacheKey = self::$derivedKeys[$memo] ??= 'netgsm-iys-token-'
            . hash_pbkdf2('sha256', $this->config->password(), $salt, self::KEY_DERIVATION_ITERATIONS, 64);
    }

    private function login(): Token
    {
        $token = $this->requestToken('/auth/v3/token', [
            'username' => $this->config->username,
            'password' => $this->config->password(),
            'scope' => self::SCOPE,
        ]);
        $this->store->save($this->cacheKey(), $token);

        return $token;
    }

    private function refresh(#[\SensitiveParameter] string $refreshToken): Token
    {
        $token = $this->requestToken('/auth/v3/token/refresh', ['refresh_token' => $refreshToken]);
        $this->store->save($this->cacheKey(), $token);

        return $token;
    }

    /**
     * @param array<string, string> $payload
     */
    private function requestToken(string $path, #[\SensitiveParameter] array $payload): Token
    {
        // JSON_THROW_ON_ERROR'un JsonException'ı json_encode çerçevesini (parametresinde şifre) yığına yazar;
        // bu yüzden hata bayrağıyla kontrol edilir ve yalnızca genel bir hata fırlatılır.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new ConfigurationException('Kimlik bilgileri JSON\'a çevrilemedi; kullanıcı adı ve şifre geçerli UTF-8 olmalıdır.');
        }

        $request = new Request(
            'POST',
            $this->config->baseUrl . $path,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => IysClient::USER_AGENT,
            ],
            $body,
        );

        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                if ($this->retryPolicy->canRetry($attempt)) {
                    $this->retryPolicy->wait($attempt++);
                    continue;
                }
                throw $e;
            }

            if ($response->status === 200) {
                return $this->parseToken($response);
            }

            $problem = $this->decode($response);
            $code = is_string($problem['code'] ?? null) ? $problem['code'] : null;

            if (
                in_array($response->status, self::RETRYABLE_STATUSES, true)
                && $code !== 'too_many_failed_attempts'
                && $this->retryPolicy->canRetry($attempt)
            ) {
                $this->retryPolicy->wait($attempt++, $response->header('Retry-After'));
                continue;
            }

            throw AuthenticationException::fromIdentity(
                $response->status,
                $code,
                is_string($problem['detail'] ?? null) ? $problem['detail'] : null,
                is_string($problem['request_id'] ?? null) ? $problem['request_id'] : null,
            );
        }
    }

    private function parseToken(Response $response): Token
    {
        $data = $this->decode($response);
        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!is_string($access) || $access === '' || !is_numeric($expiresIn) || (float) $expiresIn < 1) {
            throw AuthenticationException::fromIdentity(
                $response->status,
                'invalid_token_response',
                'Identity API yanıtında access_token ya da geçerli bir expires_in yok.',
                null,
            );
        }

        // Aşırı büyük değer tamsayı taşmasına, kesirli değer TypeError'a yol açmasın.
        $lifetime = (int) min((float) $expiresIn, (float) self::MAX_EXPIRES_IN);

        return new Token($access, is_string($refresh) && $refresh !== '' ? $refresh : null, time() + $lifetime);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
