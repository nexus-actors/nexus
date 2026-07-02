<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

/**
 * Strongly-typed configuration resolved once at boot from `WALLET_*` env vars.
 *
 * Every section is grouped so the rest of the app reads `$config->http->port`
 * instead of poking `getenv()` from every layer.
 */
final readonly class WalletConfig
{
    public function __construct(
        public WalletHttpConfig $http,
        public WalletDbConfig $db,
        public WalletAuthConfig $auth,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            http: WalletHttpConfig::fromEnv(),
            db: WalletDbConfig::fromEnv(),
            auth: WalletAuthConfig::fromEnv(),
        );
    }
}
