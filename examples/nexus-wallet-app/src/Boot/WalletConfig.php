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
    /** Built-in demo DB password — must never be used in production. */
    private const string DEMO_DB_PASSWORD = 'wallet';

    public function __construct(
        public WalletHttpConfig $http,
        public WalletDbConfig $db,
        public WalletAuthConfig $auth,
        public bool $production,
    ) {}

    public static function fromEnv(): self
    {
        $config = new self(
            http: WalletHttpConfig::fromEnv(),
            db: WalletDbConfig::fromEnv(),
            auth: WalletAuthConfig::fromEnv(),
            production: Env::get('WALLET_ENV', 'dev') === 'production',
        );

        $config->assertProductionSafe();

        return $config;
    }

    /**
     * Fail closed when running in production with any built-in demo default
     * still in place (SEC-013). A copied deployment must supply real secrets
     * rather than silently shipping the demo credentials.
     *
     * @throws DemoDefaultsInProductionException
     */
    public function assertProductionSafe(): void
    {
        if (!$this->production) {
            return;
        }

        if ($this->db->password === self::DEMO_DB_PASSWORD) {
            throw new DemoDefaultsInProductionException('the database password (WALLET_DB_PASS)');
        }

        if ($this->auth->tokens === WalletAuthConfig::DEMO_TOKENS) {
            throw new DemoDefaultsInProductionException('the user auth tokens (WALLET_AUTH_TOKENS)');
        }

        if ($this->auth->adminTokens === WalletAuthConfig::DEMO_ADMIN_TOKENS) {
            throw new DemoDefaultsInProductionException('the admin auth tokens (WALLET_ADMIN_TOKENS)');
        }
    }
}
