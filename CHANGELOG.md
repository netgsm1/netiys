# Değişiklik geçmişi

Bu dosyadaki biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) kurallarına, sürüm
numaraları [Semantic Versioning](https://semver.org/lang/tr/) kurallarına uyar.

## [3.0.2] - 2026-11-17

Güvenlik düzeltmeleri. Tüm 3.x kullanıcılarının güncellemesi önerilir.

### Güvenlik

- Paylaşılan bir `TokenStore`'da token önbellek anahtarı yalnızca kullanıcı adına bağlıydı; aynı kullanıcı
  adını yanlış şifreyle veren bir istemci önbellekteki token'ı kullanabiliyordu. Anahtar artık şifreye de
  bağlıdır. Güncellemeden sonra önbellekteki token bir kez yeniden alınır.
- `X-Request-Id`, telefon ve tarih doğrulaması sondaki satır sonunu (`"\n"`) kabul ediyordu; `X-Request-Id`
  bu yolla HTTP başlık bloğuna satır sonu taşıyabiliyordu. Desenler düzeltildi. `CurlTransport` satır sonu
  ya da NUL içeren başlıkları da reddeder.
- `Request` dökümünde `GET /consents` adresindeki alıcı numaraları açık görünüyordu; sorgu artık maskelenir.
- `Response` ve `ApiResponse` dökümleri token ve alıcı verisini açık gösteriyordu; artık maskelenir.
- `Request`, `Response` ve `ApiResponse` `json_encode()` ile (ör. Monolog bağlamında) maskelenmiş görünümü yazar.
- Şifre, token ya da alıcı bilgisi taşıyan parametreler `#[\SensitiveParameter]` ile işaretlendi; hata
  yığınlarına (`getTrace()`) açık metin olarak düşmez.

### Değişti

- `Config`, `Request`, `Response` ve `ApiResponse` serileştirilemez; `serialize()` `LogicException` fırlatır.
  İstemciyi kuyruk işinin özelliği olarak taşıyorsanız işin içinde oluşturun.
- `IysClient::VERSION` ve `User-Agent` 3.0.2 oldu.

## [3.0.1] - 2026-11-17

3.0.0 ile aynı işlevleri içerir; Packagist'te yayımlanan ilk 3.x sürümüdür.

### Değişti

- README: Netgsm'in İYS Aracı Hizmet Sağlayıcı ve Entegratör olduğu belirtildi.
- `IysClient::VERSION` ve `User-Agent` 3.0.1 oldu.

## [3.0.0] - 2026-11-17

Geriye dönük uyumlu değildir. Geçiş için [UPGRADE-3.0.md](UPGRADE-3.0.md).

### Eklendi

- Netgsm İYS API v1 (`https://ext-api.netgsm.com.tr/iys/v1`) istemcisi: `IysClient`.
- `addConsents()` / `addConsent()`: `POST /consents`, istek başına 500 izin, `erroritem` ile kısmi red.
- `searchConsents()` / `searchConsent()`: `GET /consents`, tekil ve çoklu sorgu, parçalara bölme.
- `saveWebhook()`: `POST /webhooks`.
- Identity API (`/auth/v3/token`) ile Bearer token, önbellek (`TokenStore`) ve refresh.
- Enum'lar (`ConsentType`, `ConsentStatus`, `RecipientType`, `ConsentSource`, `IysPath`) ve DTO'lar.
- Tipli hata sınıfları; izin kaydında belirsiz sonuç için `AmbiguousResultException`.
- `RetryPolicy`: artan bekleme ve `Retry-After` desteği.
- `X-Request-Id` gönderimi; `X-Thread-Id` ve `uid` sonuçlarda.
- PHPUnit testleri, PHPStan (level 8), PHP-CS-Fixer ve GitHub Actions.

### Değişti

- En düşük PHP sürümü 8.2.
- Composer paket türü `library`.

### Kaldırıldı

- `Netgsm\Iys\iys` sınıfı ve eski `api.netgsm.com.tr/iys/add`, `/iys/search` uç noktaları.
- Laravel / Symfony sürüm desteği iddiası; paket framework'ten bağımsızdır.

### Güvenlik

- TLS sertifika doğrulaması açıldı (v2'de `CURLOPT_SSL_VERIFYPEER` kapalıydı); kapatma seçeneği yoktur.
- Şifre artık her istekte gönderilmiyor; yalnızca token alınırken gönderiliyor.
- Eksik kimlik bilgisinde `'x'` ile istek gönderilmesi yerine `ConfigurationException`.
- İstekler için zaman aşımı; yalnızca HTTPS; yönlendirme izlenmiyor.
- Şifre ve token'lar `var_dump` / `print_r` dökümlerinde ve hata izlerinde gizli
  (`#[\SensitiveParameter]`, `__debugInfo`); alıcı numaraları maskeli.

## [2.0.1]

Önceki sürüm: `api.netgsm.com.tr/iys` uç noktalarını kullanan `iys` sınıfı.

[3.0.2]: https://github.com/netgsm1/netiys/compare/v3.0.1...v3.0.2
[3.0.1]: https://github.com/netgsm1/netiys/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/netgsm1/netiys/compare/v2.0.1...v3.0.0
[2.0.1]: https://github.com/netgsm1/netiys/releases/tag/v2.0.1
