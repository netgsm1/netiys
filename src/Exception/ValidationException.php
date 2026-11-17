<?php

declare(strict_types=1);

namespace Netgsm\Iys\Exception;

/**
 * 422 / errorCode 70: istekteki bir alan kurallara uymuyor. Tekrar denenmez.
 */
final class ValidationException extends ApiException
{
    /**
     * Alan bazlı hata mesajları (`data.errors`).
     *
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        $errors = $this->data['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
