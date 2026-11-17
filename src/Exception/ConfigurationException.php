<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * Eksik ya da hatalı yapılandırma (kimlik bilgisi, marka kodu, adres).
 */
final class ConfigurationException extends InvalidArgumentException
{
}
