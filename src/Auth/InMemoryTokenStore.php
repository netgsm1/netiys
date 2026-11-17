<?php

declare(strict_types=1);

namespace Netgsm\Iys\Auth;

final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, Token> */
    private array $tokens = [];

    public function get(string $key): ?Token
    {
        return $this->tokens[$key] ?? null;
    }

    public function save(string $key, Token $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
