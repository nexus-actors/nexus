<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

final readonly class WalletAuthConfig
{
    public function __construct(public string $tokens) {}

    public static function fromEnv(): self
    {
        return new self(
            tokens: Env::get(
                'WALLET_AUTH_TOKENS',
                'alice-token=alice,bob-token=bob,carol-token=carol',
            ),
        );
    }
}
