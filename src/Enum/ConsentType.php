<?php

declare(strict_types=1);

namespace Netgsm\Iys\Enum;

/**
 * İzin tipi: MESAJ (SMS), ARAMA (sesli arama), EPOSTA (e-posta).
 */
enum ConsentType: string
{
    case MESAJ = 'MESAJ';
    case ARAMA = 'ARAMA';
    case EPOSTA = 'EPOSTA';
}
