<?php

declare(strict_types=1);

namespace Netgsm\Iys\Enum;

/**
 * ONAY izin verildi, RET izin reddedildi (ret bildirimi de kayıttır).
 */
enum ConsentStatus: string
{
    case ONAY = 'ONAY';
    case RET = 'RET';
}
