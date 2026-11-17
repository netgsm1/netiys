<?php

declare(strict_types=1);

namespace Netgsm\Iys\Dto;

/**
 * `POST /webhooks` sonucu.
 */
final class WebhookResult
{
    public function __construct(
        public readonly ?string $uid,
        public readonly ?string $requestId,
        public readonly ?string $threadId,
    ) {
    }
}
