<?php

declare(strict_types=1);

namespace Netgsm\Iys\Auth;

/**
 * Token önbelleği. Varsayılanı süreç içi {@see InMemoryTokenStore}.
 *
 * PHP-FPM gibi her isteğin ayrı süreçte çalıştığı ortamlarda token'ı istekler arasında paylaşmak için
 * kendi uygulamanızı (Redis, APCu, şifreli dosya) verin. Token gizli veridir: düz metin log ya da
 * paylaşılan dizinlere yazmayın. Aynı refresh token birden fazla süreçte koordinasyonsuz yenilenmemelidir.
 */
interface TokenStore
{
    public function get(string $key): ?Token;

    public function save(string $key, Token $token): void;

    public function delete(string $key): void;
}
