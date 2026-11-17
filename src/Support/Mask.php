<?php

declare(strict_types=1);

namespace Netgsm\Iys\Support;

/**
 * Kişisel verileri log için maskeler. Alıcı numaraları ve e-posta adresleri kişisel veridir.
 */
final class Mask
{
    /**
     * `+905321234567` → `+90532*****67`, `ornek@example.com` → `o****@example.com`.
     */
    public static function recipient(#[\SensitiveParameter] string $recipient): string
    {
        $at = strrpos($recipient, '@');
        if ($at !== false) {
            $local = substr($recipient, 0, $at);
            if ($local === '') {
                return '****' . substr($recipient, $at);
            }

            return $local[0] . '****' . substr($recipient, $at);
        }

        $length = strlen($recipient);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($recipient, 0, 6) . str_repeat('*', $length - 8) . substr($recipient, -2);
    }
}
