<?php

declare(strict_types=1);

namespace Netgsm\Iys\Auth;

use Netgsm\Iys\Config;
use Netgsm\Iys\Exception\AuthenticationException;
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

    private readonly string $cacheKey;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly TokenStore $store,
        private readonly RetryPolicy $retryPolicy,
    ) {
        $this->cacheKey = 'netgsm-iys-token-' . hash('sha256', $config->baseUrl . "\0" . $config->username);
    }

    public function accessToken(): string
    {
        $token = $this->store->get($this->cacheKey);
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
        $this->store->delete($this->cacheKey);
    }

    private function login(): Token
    {
        $token = $this->requestToken('/auth/v3/token', [
            'username' => $this->config->username,
            'password' => $this->config->password(),
            'scope' => self::SCOPE,
        ]);
        $this->store->save($this->cacheKey, $token);

        return $token;
    }

    private function refresh(string $refreshToken): Token
    {
        $token = $this->requestToken('/auth/v3/token/refresh', ['refresh_token' => $refreshToken]);
        $this->store->save($this->cacheKey, $token);

        return $token;
    }

    /**
     * @param array<string, string> $payload
     */
    private function requestToken(string $path, #[\SensitiveParameter] array $payload): Token
    {
        $request = new Request(
            'POST',
            $this->config->baseUrl . $path,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => IysClient::USER_AGENT,
            ],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

        if (!is_string($access) || $access === '' || !is_numeric($expiresIn)) {
            throw AuthenticationException::fromIdentity(
                $response->status,
                'invalid_token_response',
                'Identity API yanıtında access_token ya da expires_in yok.',
                null,
            );
        }

        return new Token($access, is_string($refresh) && $refresh !== '' ? $refresh : null, time() + (int) $expiresIn);
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
