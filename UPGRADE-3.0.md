# v2'den v3'e geçiş

v3, Netgsm'in güncel **İYS API v1** servisini (`https://ext-api.netgsm.com.tr/iys/v1`) kullanır. Eski
`api.netgsm.com.tr/iys/add` ve `/iys/search` uç noktalarını kullanan `iys` sınıfı kaldırılmıştır. Geriye
dönük uyumlu değildir; aşağıdaki adımlarla geçiş yapın.

## Özet

| | v2 | v3 |
|---|---|---|
| PHP | >= 7.2.5 | **>= 8.2** |
| Uç noktalar | `api.netgsm.com.tr/iys/add`, `/iys/search` | `ext-api.netgsm.com.tr/iys/v1/consents`, `/iys/v1/webhooks` |
| Kimlik doğrulama | Kullanıcı adı ve şifre her isteğin gövdesinde | `/auth/v3/token` ile Bearer token; şifre yalnızca token alırken gönderilir |
| Sınıf | `Netgsm\Iys\iys` | `Netgsm\Iys\IysClient` |
| Girdi | Dizi | `Consent` / `ConsentQuery` nesneleri (`fromArray()` ile diziden) |
| Sonuç | `code` / `error` içeren dizi | Tipli sonuç nesneleri; hata durumunda exception |
| İstek başına kayıt | 1 | Kayıtta 500, sorguda 1000 |
| Ortam değişkenleri | `NETGSM_USERCODE`, `NETGSM_PASSWORD`, `NETGSM_BRANDCODE` | **Değişmedi** |
| TLS doğrulaması | Kapalı | Her zaman açık |
| Eksik kimlik bilgisi | `'x'` ile istek gönderiliyordu | `ConfigurationException` |

## Ön koşul: API alt kullanıcısı

v3, token'ı Netgsm Identity API'den alır. `NETGSM_USERCODE` abone numaranız, `NETGSM_PASSWORD` ise
**API Kullanıcısı** türündeki alt kullanıcının şifresi olmalıdır. Alt kullanıcıya dijital servis (NetİYS)
yetkisi verin ve hesabın API erişiminin açık olduğunu kontrol edin
([hazırlık rehberi](https://bilgibankasi.netgsm.com.tr/entegrasyonlar/api-entegrasyonu-hazirlik-rehberi)).

## İzin kaydetme

v2:

```php
use Netgsm\Iys\iys;

$data = ['type' => 'MESAJ', 'source' => 'HS_WEB', 'recipient' => '+905XXXXXXXXX',
         'recipientType' => 'BIREYSEL', 'status' => 'ONAY', 'consentDate' => '2020-11-06 09:40:00'];
$sonuc = (new iys)->iys($data);

if ($sonuc['code'] == 0) { /* ... */ }
```

v3:

```php
use Netgsm\Iys\Dto\Consent;
use Netgsm\Iys\Exception\ApiException;
use Netgsm\Iys\IysClient;

$iys = IysClient::fromEnv();

try {
    $result = $iys->addConsent(Consent::fromArray($data));
    // $result->uid, $result->rejected (kısmi red)
} catch (ApiException $e) {
    // $e->errorCode, $e->apiMessage, $e->threadId
}
```

v2'deki `refid` alanı v3'te `addConsents($consents, refId: '...')` parametresidir ve isteğin tamamına
uygulanır.

## İzin sorgulama

v2:

```php
$sonuc = (new iys)->iysadressorgula(['type' => 'MESAJ', 'recipient' => '+905XXXXXXXXX', 'recipientType' => 'BIREYSEL']);
$sonuc['query']->status;
```

v3:

```php
use Netgsm\Iys\Dto\ConsentQuery;

$record = $iys->searchConsent(ConsentQuery::fromArray(['type' => 'MESAJ', 'recipient' => '+905XXXXXXXXX', 'recipientType' => 'BIREYSEL']));

$record->isApproved();   // yalnızca ONAY izin demektir
$record->isNotFound();   // v2'deki "code 50 Kayıt Bulunamadı" yerine
```

## Hata kodları

v2'nin `code` değerleri (örneğin `50`, `70`) v3'te kullanılmaz. v3 hata kodları İYS API v1'e aittir ve
anlamları farklıdır (v3'te `50` kimlik doğrulama, `70` doğrulama hatası, `81` kayıt bulunamadı). Hata
mantığınızı yeni kodlara göre yeniden kurun: https://api-docs.netgsm.com.tr/iys/netgsm-iys-api/

## Yeni

- Toplu kayıt (500) ve toplu sorgu (1000).
- `saveWebhook()` ile webhook adresini API'den tanımlama.
- `X-Request-Id` ve `X-Thread-Id` ile isteklerin izlenmesi.
- Otomatik tekrar deneme; izin kaydında belirsiz sonuçlar için `AmbiguousResultException`.

## Laravel / Symfony

v3 framework'e bağlı değildir ve ServiceProvider ya da Facade içermez. İstemciyi kendi servis
kapsayıcınızda tekil (singleton) olarak kaydedin:

```php
// Laravel: AppServiceProvider::register()
$this->app->singleton(\Netgsm\Iys\IysClient::class, fn () => \Netgsm\Iys\IysClient::fromEnv());
```

Laravel'de `config:cache` kullanıyorsanız ortam değişkenleri `$_ENV`'e yüklenmeyebilir. Bu durumda değerleri
kendi config dosyanızdan `new Config(...)` ile verin.
