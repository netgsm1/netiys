# netgsm/iys — Netgsm İYS API PHP istemcisi

İleti Yönetim Sistemi (İYS), hizmet sağlayıcıların ticari elektronik ileti onaylarını kaydettiği ulusal
veri tabanıdır. Alıcılar İYS üzerinden onay verebilir ya da mevcut onaylarını reddedebilir.
**Netgsm, İYS Aracı Hizmet Sağlayıcı kurumdur.**

Bu paket, [Netgsm İYS API v1](https://api-docs.netgsm.com.tr/iys/) ile izin kaydetmek, izin sorgulamak ve
izin sonuçlarının bildirileceği webhook adresini tanımlamak için kullanılır.

> [!WARNING]
> **İYS'nin test ortamı (sandbox) yoktur.** Bu paketin attığı her istek canlı, ulusal İleti Yönetim
> Sistemi'ne gider. İYS'ye kaydedilen izinler hukuki kayıttır; **silinemez ve düzenlenemez.**
> Örnek, test ya da demo amacıyla gerçek bir telefon numarası veya e-posta adresiyle izin kaydetmeyin.
> Kendi testlerinizde [sahte bir transport](#test) kullanın.

## İçindekiler

- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Yapılandırma](#yapılandırma)
- [İzin kaydetme](#izin-kaydetme)
- [İzin sorgulama](#izin-sorgulama)
- [Webhook adresi tanımlama](#webhook-adresi-tanımlama)
- [Webhook bildirimlerini alma](#webhook-bildirimlerini-alma)
- [Hata yönetimi](#hata-yönetimi)
- [Token önbelleği](#token-önbelleği)
- [Test](#test)
- [v2'den geçiş](#v2den-geçiş)
- [Doküman ve destek](#doküman-ve-destek)

## Gereksinimler

- PHP **8.2** ve üzeri, `ext-curl`, `ext-json`
- Hesabınızda **İYS (NetİYS) hizmetinin aktif** olması: NetİYS kaydı, İYS kodu, NetİYS paketi ve İYS kredisi
  ([NetİYS kaydı](https://bilgibankasi.netgsm.com.tr/ileti-yonetim-sistemi/net-iys-kayit-net-iys-kullanicisi-olmak)).
- **API Kullanıcısı** türünde bir alt kullanıcı ve bu kullanıcıya **dijital servis (NetİYS) yetkisi**
  ([API entegrasyonu hazırlık rehberi](https://bilgibankasi.netgsm.com.tr/entegrasyonlar/api-entegrasyonu-hazirlik-rehberi),
  [alt kullanıcı oluşturma](https://bilgibankasi.netgsm.com.tr/abonelik-islemleri/diger-islemler/alt-kullanici-olusturma)).
- **İYS marka kodunuz** (`brandCode`). Marka kodu İYS panelinden öğrenilir; İYS kodu ile aynı şey değildir.

Laravel, Symfony ya da başka bir framework gerekmez; paket bağımsız bir PHP kütüphanesidir.

## Kurulum

```bash
composer require netgsm/iys:^3.0
```

## Yapılandırma

Kimlik bilgilerini kodda değil, ortam değişkenlerinde tutun. Değişken adları v2 ile aynıdır:

```dotenv
NETGSM_USERCODE="<abone numarası>"
NETGSM_PASSWORD="<API alt kullanıcısının şifresi>"
NETGSM_BRANDCODE="<İYS marka kodu>"
```

```php
use Netgsm\Iys\IysClient;

$iys = IysClient::fromEnv();
```

Değişkenler `$_ENV`, `$_SERVER` ve `getenv()` sırasıyla okunur. Biri eksikse istek gönderilmez,
`ConfigurationException` fırlatılır.

Değerleri doğrudan vermek için:

```php
use Netgsm\Iys\Config;
use Netgsm\Iys\Enum\IysPath;
use Netgsm\Iys\Http\CurlTransport;
use Netgsm\Iys\IysClient;

$config = new Config(
    username: $username,
    password: $password,
    brandCode: 123456,
    path: IysPath::SMSAPI,      // isteğe bağlı; gönderilmezse API SMSAPI kabul eder
);

$iys = new IysClient($config, new CurlTransport(timeout: 30, connectTimeout: 10));
```

`.env` dosyasını `.gitignore`'a ekleyin ve istemciyi yalnızca sunucu tarafında kullanın.

**`cURL 60: SSL certificate problem: unable to get local issuer certificate`** hatası, PHP'nin güvenilir kök
sertifika (CA) deposunu bulamadığını gösterir; Windows ve XAMPP kurulumlarında sık görülür. TLS doğrulamasını
kapatmayın. `php.ini` içinde `curl.cainfo` değerini bir CA dosyasına (örneğin https://curl.se/docs/caextract.html
adresindeki `cacert.pem`) yönlendirin ya da dosyayı doğrudan verin:
`new CurlTransport(caBundle: '/yol/cacert.pem')`.

## İzin kaydetme

`POST /iys/v1/consents`. Bir istekte **en fazla 500** izin gönderilir.

```php
use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Enum\ConsentSource;
use Netgsm\Iys\Enum\ConsentStatus;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;

$consent = new Consent(
    recipient: '+905XXXXXXXXX',               // telefon +90XXXXXXXXXX ya da e-posta adresi
    recipientType: RecipientType::BIREYSEL,   // BIREYSEL | TACIR
    type: ConsentType::MESAJ,                 // MESAJ | ARAMA | EPOSTA
    source: ConsentSource::HS_WEB,            // iznin gerçekte alındığı yöntem
    status: ConsentStatus::ONAY,              // ONAY | RET
    consentDate: '2026-02-20 16:46:00',       // iznin alındığı an (DateTimeInterface de olur)
);

$result = $iys->addConsents([$consent], refId: 'musteri-4711');

$result->uid;        // işlem kimliği: loglayın
$result->threadId;   // X-Thread-Id: destek talebinde paylaşın
```

**Kısmi red normaldir.** İstek başarılı olsa bile İYS bazı kayıtları reddedebilir. Reddedilen kayıtlar
kaydedilmemiştir; düzeltilip yeniden gönderilmelidir:

```php
$consents = [/* Consent nesneleri */];
$result = $iys->addConsents($consents);

foreach ($result->rejectedWith($consents) as ['consent' => $consent, 'rejection' => $rejection]) {
    // $rejection->index (0'dan başlar), $rejection->field, $rejection->message
}
```

500'den fazla izni parçalara bölün ve her parçanın sonucunu ayrı işleyin:

```php
foreach (array_chunk($consents, IysClient::MAX_CONSENTS_PER_REQUEST) as $chunk) {
    $result = $iys->addConsents($chunk);
    // $result->rejectedWith($chunk) ...
}
```

Dizi kullanan v2 kodundan geçerken `Consent::fromArray([...])` kullanılabilir.

| Alan | Değerler |
|---|---|
| `recipient` | `+90XXXXXXXXXX` ya da e-posta adresi (en fazla 255 karakter) |
| `recipientType` | `BIREYSEL`, `TACIR` |
| `type` | `MESAJ`, `ARAMA`, `EPOSTA` |
| `source` | `HS_FIZIKSEL_ORTAM`, `HS_ISLAK_IMZA`, `HS_WEB`, `HS_CAGRI_MERKEZI`, `HS_SOSYAL_MEDYA`, `HS_EPOSTA`, `HS_MESAJ`, `HS_MOBIL`, `HS_EORTAM`, `HS_ETKINLIK`, `HS_2015`, `HS_ATM`, `HS_KARAR` ([anlamları](https://bilgibankasi.netgsm.com.tr/ileti-yonetim-sistemi/iys-izin-yontemleri)) |
| `status` | `ONAY`, `RET` |
| `consentDate` | `YYYY-MM-DD HH:mm:ss` |
| `retailerCode`, `retailerAccess` | İsteğe bağlı bayi alanları; bayi yapınız yoksa göndermeyin |

Telefon numarası biçimi SMS API v3'ten farklıdır: İYS `+905XXXXXXXXX`, SMS API `905XXXXXXXXX` ister.

Resmî doküman: https://api-docs.netgsm.com.tr/iys/save-consents/

## İzin sorgulama

`GET /iys/v1/consents`. Liste varsayılan olarak 200'lük parçalarla sorgulanır; sonuçlar gönderilen
sırayla döner.

```php
use Netgsm\Iys\Dto\ConsentQuery;
use Netgsm\Iys\Enum\ConsentType;
use Netgsm\Iys\Enum\RecipientType;

$record = $iys->searchConsent(new ConsentQuery('+905XXXXXXXXX', RecipientType::BIREYSEL, ConsentType::MESAJ));

if ($record->isApproved()) {
    // ticari ileti izni var
}

$records = $iys->searchConsents([
    new ConsentQuery('+905XXXXXXXXX', RecipientType::BIREYSEL, ConsentType::MESAJ),
    new ConsentQuery('ornek@example.com', RecipientType::BIREYSEL, ConsentType::EPOSTA),
]);
```

| Metot | Anlamı |
|---|---|
| `isApproved()` | `status: ONAY`, hata yok. **Yalnızca bu durum izin demektir.** |
| `isRejected()` | `status: RET` |
| `isNotFound()` | İYS'de kayıt yok (`81`): izin yok |
| `isUnknown()` | Sonuç bilinmiyor (`112`, `120`): izin varmış gibi davranmayın, daha sonra yeniden sorgulayın |

Ticari SMS gönderiminden önce her alıcıyı tek tek sorgulamanız gerekmez: SMS API'nin `iysFilter`
parametresi izni olmayan alıcıları gönderim sırasında eler
([SMS gönderimi](https://api-docs.netgsm.com.tr/send-sms/)). Sorgu; mutabakat, müşteri ekranında izin
durumunu gösterme ve bir kaydın işlenip işlenmediğini doğrulama içindir.

Resmî doküman: https://api-docs.netgsm.com.tr/iys/search-consents/

## Webhook adresi tanımlama

`POST /iys/v1/webhooks`. İzinlerin işlenme sonuçları ve izin değişiklikleri bu adrese bildirilir. Marka
başına tek adres tutulur; yeni kayıt eskisinin yerine geçer.

```php
$result = $iys->saveWebhook('https://ornek.com/iys/webhook/' . $gizliDeger);
```

Adres HTTPS olmalıdır. Bildirim isteğinde imza başlığı tanımlı olmadığı için yolda tahmin edilemez, gizli
bir parça kullanın ve bu değeri loglamayın. Adres Netgsm portalındaki NetİYS modülünden de tanımlanabilir.

Resmî doküman: https://api-docs.netgsm.com.tr/iys/save-webhook/

## Webhook bildirimlerini alma

İzin sonuçları ve izin değişiklikleri, tanımladığınız adrese Netgsm tarafından JSON olarak POST edilir.
İYS izni hangi yolla verilmiş ya da değiştirilmiş olursa olsun bildirim gönderilir.

Alıcı adres için öneriler:

- Gövdeyi ham hâliyle kaydedip hemen `2xx` dönün, işlemeyi sonra yapın.
- Gövdeyi esnek okuyun; bilinmeyen alanları yok sayın.
- Aynı bildirim birden fazla gelebilir: tekilleştirerek işleyin.
- Bildirimin kaybolabileceğini varsayın; kritik durumlarda `searchConsents()` ile doğrulayın.
- Bildirimdeki numara ve e-posta adresleri kişisel veridir; loglarda maskeleyin (`Netgsm\Iys\Support\Mask::recipient()`).

```php
// Framework'ten bağımsız örnek
$payload = file_get_contents('php://input');
// 1) ham gövdeyi kalıcı olarak saklayın  2) 200 dönün  3) kuyruğa alıp işleyin
http_response_code(200);
```

Bildirim alanları için: https://api-docs.netgsm.com.tr/iys/webhook/

## Hata yönetimi

Paketin fırlattığı bütün hatalar `Netgsm\Iys\Exception\IysException` arayüzünü uygular. API hatalarında
mantık `errorCode` alanına göre kurulur.

| Hata | Ne zaman | Ne yapılır |
|---|---|---|
| `InvalidArgumentException` | Yerel doğrulama (numara biçimi, tarih, 500 sınırı); istek gönderilmedi | Girdiyi düzeltin |
| `ConfigurationException` | Eksik ya da hatalı yapılandırma | Ortam değişkenlerini kontrol edin |
| `AuthenticationException` | Token alınamadı ya da yeni token ile de 401 | Kimlik bilgilerini, API erişimini ve IP iznini kontrol edin; `identityCode()` nedeni verir |
| `ForbiddenException` | 403: `60`, `61`, `63` (İYS hizmeti aktif değil) | Alt kullanıcı yetkisi ve NetİYS aktivasyonu |
| `ValidationException` | 422 / `70` | `errors()` alan bazlı mesajları verir; tekrar denemeyin |
| `RateLimitException` | 429 / `150`, tekrar hakları tükendi | İstek hızını düşürün |
| `AmbiguousResultException` | İzin kaydında `424`/`112`, `500`/`110` ya da istek gönderildikten sonra ağ hatası | **Partiyi körlemesine yeniden göndermeyin**; webhook ve `searchConsents()` ile kontrol edin |
| `TransportException` | Bağlantı kurulamadı ya da okuma isteğinde ağ hatası | Ağ ve DNS ayarlarını kontrol edin |
| `ApiException` | Diğer API hataları (`120`, `130`, `81` vb.) | `errorCode`, `apiMessage`, `threadId` |

Otomatik tekrar deneme (varsayılan 2 tekrar, 1 sn ve 2 sn bekleme, `Retry-After`'a uyulur):

- Bütün uç noktalarda: `503`/`141`, `429`/`150`, bağlantı kurulamadı.
- Yalnızca sorgu ve webhook'ta: `424`/`112`, `500`/`110`, ağ hatası.
- 401 alınırsa token yenilenir ve istek **bir kez** tekrarlanır.

```php
use Netgsm\Iys\Exception\AmbiguousResultException;
use Netgsm\Iys\Exception\ApiException;

try {
    $result = $iys->addConsents($chunk);
} catch (AmbiguousResultException $e) {
    // $e->requestId ve $e->threadId'yi saklayın; kayıtların durumunu daha sonra doğrulayın
} catch (ApiException $e) {
    // $e->errorCode, $e->httpStatus, $e->apiMessage, $e->threadId
}
```

Tekrar deneme kuralını değiştirmek için `new IysClient($config, retryPolicy: new RetryPolicy(maxRetries: 3))`.

Destek talebinde `threadId` (X-Thread-Id), varsa `uid`, isteğin zamanı ve hata yanıtını paylaşın; şifre
ve token paylaşmayın. Hata kodlarının tamamı: https://api-docs.netgsm.com.tr/iys/netgsm-iys-api/

## Token önbelleği

İstemci, Netgsm Identity API'den (`/auth/v3/token`) Bearer token alır ve süresi dolana kadar yeniden
kullanır; süresi yaklaşınca refresh token ile yeniler. Token isteği IP başına dakikada 30 ile sınırlıdır.

Varsayılan `InMemoryTokenStore` token'ı yalnızca o PHP süreci boyunca tutar. PHP-FPM gibi her isteğin ayrı
çalıştığı ortamlarda istemciyi uzun süreli bir süreçte (kuyruk işçisi gibi) kullanın ya da token'ı
paylaşmak için kendi `TokenStore` uygulamanızı verin:

```php
use Netgsm\Iys\Auth\Token;
use Netgsm\Iys\Auth\TokenStore;

final class RedisTokenStore implements TokenStore
{
    public function get(string $key): ?Token { /* ... */ }
    public function save(string $key, Token $token): void { /* ... */ }
    public function delete(string $key): void { /* ... */ }
}

$iys = new IysClient($config, tokenStore: new RedisTokenStore(/* ... */));
```

Token gizli veridir: düz metin log ya da paylaşılan dizinlere yazmayın.

## Test

Kendi kodunuzu test ederken gerçek istek atmayın. `Netgsm\Iys\Http\Transport` arayüzünü uygulayan sahte
bir sınıf verin:

```php
use Netgsm\Iys\Http\Request;
use Netgsm\Iys\Http\Response;
use Netgsm\Iys\Http\Transport;

final class FakeTransport implements Transport
{
    public function send(Request $request): Response
    {
        if (str_ends_with((string) parse_url($request->url, PHP_URL_PATH), '/auth/v3/token')) {
            return new Response(200, [], json_encode(['access_token' => 't', 'expires_in' => 86400, 'refresh_token' => 'r']));
        }

        return new Response(200, [], json_encode(['success' => true, 'errorCode' => 0, 'message' => 'success', 'data' => ['uid' => 'test']]));
    }
}

$iys = new IysClient(new Config('8500000000', 'sifre', 123456), new FakeTransport());
```

Paketin kendi testleri:

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
composer cs        # kod stili
```

Canlıya geçerken ilk denemeyi salt okunur bir sorguyla (`searchConsent()`), kendi müşteri kaydınızdaki bir
numarayla yapın. İzin kaydını yalnızca gerçekten alınmış bir izin için çağırın.

## v2'den geçiş

v3, eski `api.netgsm.com.tr/iys` uç noktaları yerine İYS API v1'i kullanır ve eski `iys` sınıfını kaldırır.
Ayrıntılar: [UPGRADE-3.0.md](UPGRADE-3.0.md). Değişiklik geçmişi: [CHANGELOG.md](CHANGELOG.md).

## Doküman ve destek

| Kaynak | Adres |
|---|---|
| İYS API dokümanı | https://api-docs.netgsm.com.tr/iys/ |
| Token (Identity API) | https://api-docs.netgsm.com.tr/auth/token/ |
| Bilgi Bankası: İYS | https://bilgibankasi.netgsm.com.tr/ileti-yonetim-sistemi/ileti-yoenetim-sistemi-iys-nedir |
| NetİYS SSS | https://bilgibankasi.netgsm.com.tr/ileti-yonetim-sistemi/sikca-sorulan-sorular-netiys |
| Teknik destek | teknikdestek@netgsm.com.tr |
| Çağrı merkezi | +90 850 303 0 303 · +90 312 911 0 911 |

Güvenlik açığı bildirimi için [SECURITY.md](SECURITY.md). Lisans: [MIT](LICENSE).
