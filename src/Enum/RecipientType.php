<?php

declare(strict_types=1);

namespace Netgsm\Iys\Enum;

/**
 * Alıcı tipi: BIREYSEL ya da TACIR.
 */
enum RecipientType: string
{
    case BIREYSEL = 'BIREYSEL';
    case TACIR = 'TACIR';
}
