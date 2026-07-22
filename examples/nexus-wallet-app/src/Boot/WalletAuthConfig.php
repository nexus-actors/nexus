<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

final readonly class WalletAuthConfig
{
    /** Built-in demo values — must never be used in production. */
    public const string DEMO_TOKENS = 'alice-token=alice,bob-token=bob,carol-token=carol';
    public const string DEMO_ADMIN_TOKENS = 'admin-token=root';

    /**
     * @param string $tokens `token=userId` pairs (comma-separated) for regular
     *        `user`-role principals with wallet:read/write scopes.
     * @param string $adminTokens `token=userId` pairs for `admin`-role
     *        principals (also granted wallet:admin scope). Kept separate so an
     *        admin token is never accidentally minted from the user list.
     */
    public function __construct(public string $tokens, public string $adminTokens) {}

    public static function fromEnv(): self
    {
        return new self(
            tokens: Env::get('WALLET_AUTH_TOKENS', self::DEMO_TOKENS),
            adminTokens: Env::get('WALLET_ADMIN_TOKENS', self::DEMO_ADMIN_TOKENS),
        );
    }
}
