<?php

declare(strict_types=1);

namespace Netgsm\Iys\Enum;

/**
 * İznin gerçekte alındığı yöntem. Açıklamalar: https://bilgibankasi.netgsm.com.tr/ileti-yonetim-sistemi/iys-izin-yontemleri
 */
enum ConsentSource: string
{
    case HS_FIZIKSEL_ORTAM = 'HS_FIZIKSEL_ORTAM';
    case HS_ISLAK_IMZA = 'HS_ISLAK_IMZA';
    case HS_WEB = 'HS_WEB';
    case HS_CAGRI_MERKEZI = 'HS_CAGRI_MERKEZI';
    case HS_SOSYAL_MEDYA = 'HS_SOSYAL_MEDYA';
    case HS_EPOSTA = 'HS_EPOSTA';
    case HS_MESAJ = 'HS_MESAJ';
    case HS_MOBIL = 'HS_MOBIL';
    case HS_EORTAM = 'HS_EORTAM';
    case HS_ETKINLIK = 'HS_ETKINLIK';
    case HS_2015 = 'HS_2015';
    case HS_ATM = 'HS_ATM';
    case HS_KARAR = 'HS_KARAR';
}
