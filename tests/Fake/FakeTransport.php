<?php

declare(strict_types=1);

namespace Netgsm\Iys\Tests\Fake;

use Netgsm\Iys\Http\Request;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Http\Transport;

/**
 * Gerçek istek atmayan test ikizi. Yanıtlar yol (path) bazında sıraya konur.
 */
final class FakeTransport implements Transport
{
    public const TOKEN_PATH = '/auth/v3/token';
    public const REFRESH_PATH = '/auth/v3/token/refresh';
    public const CONSENTS_PATH = '/iys/v1/consents';
    public const WEBHOOKS_PATH = '/iys/v1/webhooks';

    /** @var list<Request> */
    public array $requests = [];

    /** @var array<string, list<Response|\Throwable>> */
    private array $queues = [];

    public function queue(string $path, Response|\Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queues[$path][] = $item;
        }

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $path = (string) parse_url($request->url, PHP_URL_PATH);

        if (($this->queues[$path] ?? []) === []) {
            throw new \LogicException(sprintf('Beklenmeyen istek: %s %s', $request->method, $path));
        }

        $item = array_shift($this->queues[$path]);
        if ($item instanceof \Throwable) {
            throw $item;
        }

        return $item;
    }

    /**
     * @return list<Request>
     */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (Request $r): bool => parse_url($r->url, PHP_URL_PATH) === $path,
        ));
    }

    public function remaining(string $path): int
    {
        return count($this->queues[$path] ?? []);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $headers
     */
    public static function api(int $status, int $errorCode, array $data = [], string $message = 'success', array $headers = []): Response
    {
        return self::json($status, [
            'success' => $errorCode === 0,
            'errorCode' => $errorCode,
            'message' => $message,
            'data' => $data,
        ], $headers + ['X-Thread-Id' => 'thread-test']);
    }

    public static function token(string $access = 'access-1', string $refresh = 'refresh-1', int $expiresIn = 86400): Response
    {
        return self::json(200, [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'refresh_token' => $refresh,
            'scope' => 'sms',
        ]);
    }
}
