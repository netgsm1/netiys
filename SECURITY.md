# Güvenlik

## Desteklenen sürümler

| Sürüm | Destek |
|---|---|
| 3.x | Evet |
| 2.x ve öncesi | Hayır. TLS doğrulaması kapalıdır; 3.x'e geçin. |

## Açık bildirme

Güvenlik açıklarını herkese açık bir issue olarak değil, **teknikdestek@netgsm.com.tr** adresine e-postayla
bildirin. Etkilenen sürümü, açığın nasıl tekrarlanacağını ve olası etkisini yazın. Bildirime şifre, token
ya da gerçek alıcı verisi eklemeyin.

## Paketin güvenlik özellikleri

- TLS sertifika ve host doğrulaması her zaman açıktır; kapatma seçeneği yoktur.
- Yalnızca HTTPS adreslerine istek gönderilir ve yönlendirmeler izlenmez.
- Şifre yalnızca token alınırken gönderilir; İYS istekleri Bearer token kullanır.
- Şifre, token ve alıcı bilgileri `print_r`, `var_dump` ve `json_encode` çıktılarında maskelenir
  (`Config`, `Token`, `Request`, `Response`, `ApiResponse`, `ApiException`, `Consent`, `ConsentQuery`,
  `ConsentRecord`). Hata yığınlarında (`getTrace()`) bu değerleri taşıyan parametreler
  `#[\SensitiveParameter]` ile gizlenir.
- `Config`, `Request`, `Response` ve `ApiResponse` serileştirilemez (`serialize()` ve `unserialize()` hata
  verir); şifre ve token kuyruk, önbellek ya da oturum verisine düşmez.
- Paketin kendi hata mesajlarında alıcı numarası ve e-posta adresi yer almaz. API'nin döndürdüğü hata
  metni (`apiMessage`) olduğu gibi aktarılır.
- HTTP başlıklarında satır sonu ve NUL karakteri reddedilir; `X-Request-Id` yalnızca `A-Z a-z 0-9 . _ -` içerir.
- `baseUrl` yalnızca HTTPS olabilir; kullanıcı bilgisi (`https://a@b`), sorgu ve `#` içeremez.
- Token önbellek anahtarı adres, kullanıcı adı ve şifreden PBKDF2 (100.000 tur) ile türetilir: paylaşılan
  bir depoda yanlış şifre başka bir hesabın token'ını kullanamaz ve anahtar adından şifre tahmini yavaştır.
- İzin kaydı (`POST /consents`) işlenmiş olabileceği hiçbir durumda yeniden gönderilmez; sonuç belirsizse
  `AmbiguousResultException` fırlatılır.

Sınırlar:

- `var_export()` ve nesne özelliklerini yansıma (reflection) ile okuyan araçlar maskelemeyi atlar. Bu
  nesneleri loglamak gerekiyorsa `json_encode()` kullanın.
- `Consent`, `ConsentQuery`, `ConsentRecord` ve `Token` `serialize()` ile açık metin olarak serileşir (kuyruk
  işlerinde kullanılabilmeleri için). Serileştirilmiş veriyi kişisel veri ya da gizli veri olarak koruyun.

## Kullanırken

- Kimlik bilgilerini koda ve depoya yazmayın; ortam değişkeni ya da gizli değişken yönetimi kullanın.
- Netgsm hesabınızda IP kısıtlaması tanımlayın.
- Kendi `TokenStore` uygulamanızda token'ı güvenli bir yerde tutun.
- Webhook adresinde HTTPS ve tahmin edilemez bir yol kullanın; alıcıda yolu `hash_equals()` ile doğrulayın.
- Webhook bildirimleri imzalı değildir: bildirimdeki `ONAY`'ı tek başına gönderim yetkisi saymayın.
