<?php

declare(strict_types=1);

namespace Netgsm\Iys\Enum;

/**
 * İsteğin geldiği kanal; gönderilmezse API SMSAPI kabul eder.
 */
enum IysPath: string
{
    case SANTRAL = 'SANTRAL';
    case SMSAPI = 'SMSAPI';
    case WEBPORTAL = 'WEBPORTAL';
}
