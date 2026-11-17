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
- Şifre, token ve alıcı bilgileri `print_r`, `var_dump` ve `json_encode` çıktılarında maskelenir.
  Hata yığınlarında (`getTrace()`) bu değerleri taşıyan parametreler `#[\SensitiveParameter]` ile gizlenir.
- `Config`, `Request`, `Response` ve `ApiResponse` serileştirilemez (`serialize()` hata verir); şifre ve
  token kuyruk, önbellek ya da oturum verisine düşmez.
- Hata mesajlarında alıcı numarası ve e-posta adresi yer almaz.
- HTTP başlıklarında satır sonu ve NUL karakteri reddedilir; `X-Request-Id` yalnızca `A-Z a-z 0-9 . _ -` içerir.
- Token önbellek anahtarı kullanıcı adı ve şifreye bağlıdır; paylaşılan bir depoda yanlış şifre başka
  bir hesabın token'ını kullanamaz.

`var_export()` ve nesne özelliklerini yansıma (reflection) ile okuyan araçlar maskelemeyi atlar. Bu nesneleri
loglamak gerekiyorsa `json_encode()` kullanın.

## Kullanırken

- Kimlik bilgilerini koda ve depoya yazmayın; ortam değişkeni ya da gizli değişken yönetimi kullanın.
- Netgsm hesabınızda IP kısıtlaması tanımlayın.
- Kendi `TokenStore` uygulamanızda token'ı güvenli bir yerde tutun.
- Webhook adresinde HTTPS ve tahmin edilemez bir yol kullanın.
