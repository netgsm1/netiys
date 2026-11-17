<?php

declare(strict_types=1);

namespace Netgsm\Iys;

use Netgsm\Iys\Auth\InMemoryTokenStore;
use Netgsm\Iys\Auth\TokenProvider;
use Netgsm\Iys\Auth\TokenStore;
use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Dto\ConsentRecord;
use Netgsm\Iys\Dto\RejectedConsent;
use Netgsm\Iys\Dto\SaveConsentsResult;
use Netgsm\Iys\Dto\WebhookResult;
use Netgsm\Iys\Exception\AmbiguousResultException;
use Netgsm\Iys\Exception\ApiException;
use Netgsm\Iys\Exception\AuthenticationException;
use Netgsm\Iys\Exception\ForbiddenException;
use Netgsm\Iys\Exception\InvalidArgumentException;
use Netgsm\Iys\Exception\RateLimitException;
use Netgsm\Iys\Exception\TransportException;
use Netgsm\Iys\Exception\ValidationException;
use Netgsm\Iys\Http\ApiResponse;
use Netgsm\Iys\Http\CurlTransport;
use Netgsm\Iys\Http\Request;
use Netgsm\Iys\Http\Transport;
use Netgsm\Iys\Support\Validate;

/**
 * Netgsm İYS API v1 istemcisi (`https://ext-api.netgsm.com.tr/iys/v1`).
 *
 * İYS'nin sandbox'ı yoktur: her istek canlı, ulusal İleti Yönetim Sistemi'ne gider.
 * Kaydedilen izinler hukuki kayıttır ve silinemez. Testlerde sahte bir {@see Transport} kullanın.
 *
 * Resmî doküman: https://api-docs.netgsm.com.tr/iys/
 */
final class IysClient
{
    public const VERSION = '3.0.2';
    public const USER_AGENT = 'netgsm-iys-php/' . self::VERSION;

    /** `POST /consents` istek başına en fazla kayıt. */
    public const MAX_CONSENTS_PER_REQUEST = 500;

    /** `GET /consents` sözleşmedeki üst sınır. */
    public const MAX_QUERIES_PER_REQUEST = 1000;

    /** Adres satırı uzunluğu için güvenli varsayılan parça boyutu. */
    public const DEFAULT_QUERY_CHUNK_SIZE = 200;

    private const API_PREFIX = '/iys/v1';

    private const AUTH_ERROR_CODES = [50, 51, 52, 53];
    private const FORBIDDEN_ERROR_CODES = [60, 61, 63];
    private const VALIDATION_ERROR_CODE = 70;
    private const NOT_FOUND_ERROR_CODE = 81;
    private const DEPENDENCY_ERROR_CODE = 112;
    private const SERVER_ERROR_CODE = 110;
    private const INTEGRATION_ERROR_CODE = 141;
    private const RATE_LIMIT_ERROR_CODE = 150;

    private readonly Transport $transport;
    private readonly RetryPolicy $retryPolicy;
    private readonly TokenProvider $tokens;

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?TokenStore $tokenStore = null,
        ?RetryPolicy $retryPolicy = null,
    ) {
        $this->transport = $transport ?? new CurlTransport();
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->tokens = new TokenProvider(
            $config,
            $this->transport,
            $tokenStore ?? new InMemoryTokenStore(),
            $this->retryPolicy,
        );
    }

    /**
     * `NETGSM_USERCODE`, `NETGSM_PASSWORD` ve `NETGSM_BRANDCODE` ortam değişkenleriyle oluşturur.
     */
    public static function fromEnv(?Transport $transport = null, ?TokenStore $tokenStore = null): self
    {
        return new self(Config::fromEnv(), $transport, $tokenStore);
    }

    /**
     * İzinleri İYS'ye kaydeder (`POST /consents`), istek başına en fazla 500 kayıt.
     *
     * Daha fazla kayıt için listeyi 500'lük parçalara bölüp her parçanın sonucunu ayrı işleyin.
     * Kısmi red normaldir: {@see SaveConsentsResult::$rejected} her zaman kontrol edilmelidir.
     *
     * Yalnızca 503 (141) ve 429 (150) tekrar denenir. 424 (112), 500 (110) ve istek gönderildikten
     * sonraki ağ hatalarında kaydın işlenip işlenmediği bilinmez: {@see AmbiguousResultException}
     * fırlatılır, parti körlemesine yeniden gönderilmemelidir.
     *
     * @param list<Consent> $consents
     *
     * @throws AmbiguousResultException
     * @throws ApiException
     * @throws TransportException       Bağlantı kurulamadı; istek sunucuya ulaşmadı.
     * @throws InvalidArgumentException
     */
    public function addConsents(array $consents, ?string $refId = null, ?string $requestId = null): SaveConsentsResult
    {
        $count = count($consents);
        if ($count === 0 || $count > self::MAX_CONSENTS_PER_REQUEST) {
            throw new InvalidArgumentException(sprintf(
                'Bir istekte 1 ile %d arasında izin gönderilebilir, %d verildi.',
                self::MAX_CONSENTS_PER_REQUEST,
                $count,
            ));
        }

        $permissions = [];
        foreach (array_values($consents) as $i => $consent) {
            if (!$consent instanceof Consent) {
                throw new InvalidArgumentException(sprintf('consents[%d] bir %s nesnesi olmalıdır.', $i, Consent::class));
            }
            $permissions[] = $consent->toArray();
        }

        $body = $this->baseBody() + array_filter(['refId' => Validate::refId($refId, 'refId')], static fn ($v) => $v !== null);
        $body['permissions'] = $permissions;

        $response = $this->execute('POST', '/consents', null, $body, false, $requestId);
        $data = $response->dataArray();

        // Reddedilen kayıtlar okunamazsa sessizce atlanmaz: "hepsi kaydedildi" sanılmasın diye sonuç belirsizdir.
        $rejected = [];
        $items = $data['erroritem'] ?? [];
        if (!is_array($items) || !array_is_list($items)) {
            throw $this->unreadableRejections($response);
        }
        foreach ($items as $item) {
            if (!is_array($item) || !is_numeric($item['index'] ?? null)) {
                throw $this->unreadableRejections($response);
            }
            $rejected[] = new RejectedConsent(
                (int) $item['index'],
                is_scalar($item['field'] ?? null) ? (string) $item['field'] : '',
                is_scalar($item['message'] ?? null) ? (string) $item['message'] : '',
            );
        }

        return new SaveConsentsResult(
            is_scalar($data['uid'] ?? null) ? (string) $data['uid'] : null,
            $rejected,
            $response->requestId,
            $response->threadId,
        );
    }

    /**
     * Tek bir izni kaydeder. Çok sayıda izin için {@see addConsents()} kullanın.
     */
    public function addConsent(Consent $consent, ?string $refId = null, ?string $requestId = null): SaveConsentsResult
    {
        return $this->addConsents([$consent], $refId, $requestId);
    }

    /**
     * Alıcıların İYS'deki izin durumunu sorgular (`GET /consents`).
     *
     * Liste `$chunkSize`'lık parçalarla sorgulanır; sonuçlar gönderilen sırayla döner.
     * İzin kararı için {@see ConsentRecord::isApproved()} kullanın.
     *
     * @param list<ConsentQuery> $queries
     *
     * @return list<ConsentRecord>
     *
     * @throws ApiException
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function searchConsents(array $queries, ?string $refId = null, int $chunkSize = self::DEFAULT_QUERY_CHUNK_SIZE): array
    {
        if ($queries === []) {
            throw new InvalidArgumentException('En az bir sorgu kaydı verilmelidir.');
        }
        if ($chunkSize < 1 || $chunkSize > self::MAX_QUERIES_PER_REQUEST) {
            throw new InvalidArgumentException(sprintf('chunkSize 1 ile %d arasında olmalıdır.', self::MAX_QUERIES_PER_REQUEST));
        }
        $queries = array_values($queries);
        foreach ($queries as $i => $query) {
            if (!$query instanceof ConsentQuery) {
                throw new InvalidArgumentException(sprintf('queries[%d] bir %s nesnesi olmalıdır.', $i, ConsentQuery::class));
            }
        }
        $refId = Validate::refId($refId, 'refId');

        $records = [];
        foreach (array_chunk($queries, $chunkSize) as $chunk) {
            foreach ($this->searchChunk($chunk, $refId) as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Tek bir alıcının izin durumunu sorgular. Kayıt yoksa {@see ConsentRecord::isNotFound()} true döner.
     */
    public function searchConsent(ConsentQuery $query): ConsentRecord
    {
        return $this->searchConsents([$query])[0];
    }

    /**
     * İzin sonuçlarının bildirileceği adresi kaydeder ya da değiştirir (`POST /webhooks`).
     * Marka başına tek adres tutulur; yeni kayıt eskisinin yerine geçer.
     *
     * Adres HTTPS olmalıdır. Sözleşmede imza başlığı olmadığı için yolda tahmin edilemez, gizli bir
     * parça kullanın (`https://ornek.com/iys/webhook/<rastgele-gizli-değer>`).
     */
    public function saveWebhook(#[\SensitiveParameter] string $webhookUrl, ?string $requestId = null): WebhookResult
    {
        if (
            !str_starts_with(strtolower($webhookUrl), 'https://')
            || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('webhookUrl geçerli bir HTTPS adresi olmalıdır.');
        }

        $body = $this->baseBody();
        $body['webhookUrl'] = $webhookUrl;

        $response = $this->execute('POST', '/webhooks', null, $body, true, $requestId);
        $data = $response->dataArray();

        return new WebhookResult(
            is_scalar($data['uid'] ?? null) ? (string) $data['uid'] : null,
            $response->requestId,
            $response->threadId,
        );
    }

    /**
     * @param list<ConsentQuery> $chunk
     *
     * @return list<ConsentRecord>
     */
    private function searchChunk(array $chunk, ?string $refId): array
    {
        $query = ['brandCode' => $this->config->brandCode];
        if ($this->config->path !== null) {
            $query['path'] = $this->config->path->value;
        }
        if ($refId !== null) {
            $query['refId'] = $refId;
        }
        $query['data'] = array_map(static fn (ConsentQuery $q): array => $q->toArray(), $chunk);

        try {
            $response = $this->execute('GET', '/consents', $query, null, true, null);
        } catch (ApiException $e) {
            // Tek kayıtlı sorgu tekil sorgu olarak işlenir; kayıt yoksa istek 404 / 81 ile döner.
            if (count($chunk) === 1 && $e->errorCode === self::NOT_FOUND_ERROR_CODE) {
                return [ConsentRecord::notFound($chunk[0], $e->apiMessage)];
            }
            throw $e;
        }

        $data = $response->data;
        if (!is_array($data) || !array_is_list($data) || count($data) !== count($chunk)) {
            throw new ApiException(
                'İYS API sorgu yanıtındaki kayıt sayısı gönderilen kayıt sayısıyla eşleşmiyor.',
                $response->httpStatus,
                $response->errorCode,
                $response->message,
                [],
                $response->requestId,
                $response->threadId,
            );
        }

        $records = [];
        foreach ($chunk as $i => $item) {
            $record = ConsentRecord::fromArray(is_array($data[$i]) ? $data[$i] : [], $item);
            // Sonuçlar sırayla eşleştirilir; sıra kayarsa bir alıcının izni başkasına yazılmasın.
            if (self::normalizeRecipient($record->recipient) !== self::normalizeRecipient($item->recipient)) {
                throw new ApiException(
                    'İYS API sorgu yanıtındaki alıcılar gönderilen sırayla eşleşmiyor.',
                    $response->httpStatus,
                    $response->errorCode,
                    $response->message,
                    [],
                    $response->requestId,
                    $response->threadId,
                );
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Telefonda yalnızca rakamlar (`+905…` ile `905…` aynı), e-postada küçük harf karşılaştırılır.
     */
    private static function normalizeRecipient(#[\SensitiveParameter] string $recipient): string
    {
        $recipient = trim($recipient);

        return str_contains($recipient, '@') ? strtolower($recipient) : (string) preg_replace('/\D+/', '', $recipient);
    }

    private function unreadableRejections(ApiResponse $response): AmbiguousResultException
    {
        return new AmbiguousResultException(
            'İzin kaydı yanıtındaki reddedilen kayıt listesi (erroritem) okunamadı; hangi kayıtların kaydedildiği '
            . 'belirsiz. Partiyi körlemesine yeniden göndermeyin; durumu searchConsents() ile kontrol edin.',
            $response->requestId,
            $response->threadId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseBody(): array
    {
        $body = ['brandCode' => $this->config->brandCode];
        if ($this->config->path !== null) {
            $body['path'] = $this->config->path->value;
        }

        return $body;
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param bool                      $idempotent Aynı isteği tekrar göndermek güvenli mi (GET, POST /webhooks).
     */
    private function execute(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $query,
        #[\SensitiveParameter] ?array $body,
        bool $idempotent,
        ?string $requestId,
    ): ApiResponse {
        $requestId = $requestId !== null ? Validate::requestId($requestId) : self::generateRequestId();

        $url = $this->config->baseUrl . self::API_PREFIX . $path;
        if ($query !== null) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        // JSON_THROW_ON_ERROR'un JsonException'ı json_encode çerçevesini (parametresinde alıcılar) yığına yazar.
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encodedBody === false) {
                throw new InvalidArgumentException('İstek gövdesi JSON\'a çevrilemedi; metin alanları geçerli UTF-8 olmalıdır.');
            }
        }

        $attempt = 0;
        $reauthenticated = false;

        while (true) {
            $headers = [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->tokens->accessToken(),
                'User-Agent' => self::USER_AGENT,
                'X-Request-Id' => $requestId,
            ];
            if ($encodedBody !== null) {
                $headers['Content-Type'] = 'application/json';
            }

            try {
                $httpResponse = $this->transport->send(new Request($method, $url, $headers, $encodedBody));
            } catch (TransportException $e) {
                $safeToRetry = $idempotent || !$e->requestMayHaveBeenSent;
                if ($safeToRetry && $this->retryPolicy->canRetry($attempt)) {
                    $this->retryPolicy->wait($attempt++);
                    continue;
                }
                if ($safeToRetry) {
                    throw new TransportException($e->getMessage(), $e->requestMayHaveBeenSent, $requestId, $e->getCode(), $e);
                }
                throw new AmbiguousResultException(
                    'İstek gönderildikten sonra bağlantı koptu; kayıtların İYS\'ye ulaşıp ulaşmadığı bilinmiyor. '
                    . 'Partiyi yeniden göndermeden önce durumu searchConsents() ve webhook bildirimleriyle kontrol edin.',
                    $requestId,
                    null,
                    $e,
                );
            }

            $response = ApiResponse::fromResponse($httpResponse, $requestId);
            if ($response->isSuccessful()) {
                return $response;
            }

            $code = $response->errorCode;
            $status = $response->httpStatus;

            if ($status === 401 || in_array($code, self::AUTH_ERROR_CODES, true)) {
                if (!$reauthenticated) {
                    // Token geçersiz ya da süresi dolmuş: yeniden giriş yapılır, istek bir kez tekrarlanır.
                    $this->tokens->invalidate();
                    $reauthenticated = true;
                    continue;
                }
                throw AuthenticationException::fromResponse($response);
            }

            if ($this->isRetryable($response, $idempotent) && $this->retryPolicy->canRetry($attempt)) {
                $this->retryPolicy->wait($attempt++, $httpResponse->header('Retry-After'));
                continue;
            }

            if (!$idempotent && $this->isAmbiguous($response)) {
                throw new AmbiguousResultException(
                    sprintf(
                        'İzin kaydının sonucu belirsiz (HTTP %d, errorCode %s). Partiyi körlemesine yeniden '
                        . 'göndermeyin; durumu searchConsents() ve webhook bildirimleriyle kontrol edin, '
                        . 'gerekirse X-Thread-Id ile teknik desteğe başvurun.',
                        $status,
                        $code ?? '-',
                    ),
                    $requestId,
                    $response->threadId,
                    ApiException::fromResponse($response),
                );
            }

            throw $this->toException($response);
        }
    }

    private function isRetryable(ApiResponse $response, bool $idempotent): bool
    {
        $code = $response->errorCode;
        $status = $response->httpStatus;

        // İstek işlenmemiştir; her uç noktada tekrar denenebilir.
        if (in_array($code, [self::INTEGRATION_ERROR_CODE, self::RATE_LIMIT_ERROR_CODE], true)) {
            return true;
        }
        // Zarfsız 429 istek işlenmeden reddedilmiştir. Zarfsız 503 ise (ör. ağ geçidinin "upstream reset" yanıtı)
        // isteği arka uca ilettikten sonra da dönebilir; bu yüzden yalnızca idempotent isteklerde tekrar denenir.
        if ($code === null && ($status === 429 || ($status === 503 && $idempotent))) {
            return true;
        }

        if (!$idempotent) {
            return false;
        }

        return in_array($code, [self::DEPENDENCY_ERROR_CODE, self::SERVER_ERROR_CODE], true)
            || ($code === null && $status >= 500);
    }

    private function isAmbiguous(ApiResponse $response): bool
    {
        $code = $response->errorCode;
        if (in_array($code, [self::DEPENDENCY_ERROR_CODE, self::SERVER_ERROR_CODE], true)) {
            return true;
        }

        // Zarf okunamadı: 2xx ya da 5xx yanıtta kaydın işlenip işlenmediği bilinmez.
        return $code === null
            && ($response->httpStatus >= 500 || ($response->httpStatus >= 200 && $response->httpStatus < 300));
    }

    private function toException(ApiResponse $response): ApiException
    {
        $code = $response->errorCode;

        return match (true) {
            in_array($code, self::FORBIDDEN_ERROR_CODES, true) || ($code === null && $response->httpStatus === 403)
                => ForbiddenException::fromResponse($response),
            $code === self::VALIDATION_ERROR_CODE => ValidationException::fromResponse($response),
            $code === self::RATE_LIMIT_ERROR_CODE || ($code === null && $response->httpStatus === 429)
                => RateLimitException::fromResponse($response),
            default => ApiException::fromResponse($response),
        };
    }

    private static function generateRequestId(): string
    {
        return 'iys-' . bin2hex(random_bytes(12));
    }
}
