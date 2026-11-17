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
- Şifre ve token'lar dökümlerde ve hata izlerinde görünmez.
- Hata mesajlarında alıcı numarası ve e-posta adresi yer almaz.

## Kullanırken

- Kimlik bilgilerini koda ve depoya yazmayın; ortam değişkeni ya da gizli değişken yönetimi kullanın.
- Netgsm hesabınızda IP kısıtlaması tanımlayın.
- Kendi `TokenStore` uygulamanızda token'ı güvenli bir yerde tutun.
- Webhook adresinde HTTPS ve tahmin edilemez bir yol kullanın.
