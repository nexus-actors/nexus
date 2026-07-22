<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Tests\Unit;

use Monadial\Nexus\Example\Wallet\Boot\DemoDefaultsInProductionException;
use Monadial\Nexus\Example\Wallet\Boot\WalletAuthConfig;
use Monadial\Nexus\Example\Wallet\Boot\WalletConfig;
use Monadial\Nexus\Example\Wallet\Boot\WalletDbConfig;
use Monadial\Nexus\Example\Wallet\Boot\WalletHttpConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SEC-013: the wallet demo must fail closed in production when it is still
 * configured with any built-in demo secret/default.
 */
final class WalletConfigProductionSafetyTest extends TestCase
{
    #[Test]
    public function dev_mode_allows_demo_defaults(): void
    {
        $this->config(production: false)->assertProductionSafe();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function production_rejects_demo_database_password(): void
    {
        $this->expectException(DemoDefaultsInProductionException::class);
        $this->expectExceptionMessage('database password');

        $this->config(production: true, dbPassword: 'wallet')->assertProductionSafe();
    }

    #[Test]
    public function production_rejects_demo_user_tokens(): void
    {
        $this->expectException(DemoDefaultsInProductionException::class);
        $this->expectExceptionMessage('user auth tokens');

        $this->config(
            production: true,
            dbPassword: 's3cret',
            tokens: WalletAuthConfig::DEMO_TOKENS,
        )->assertProductionSafe();
    }

    #[Test]
    public function production_rejects_demo_admin_tokens(): void
    {
        $this->expectException(DemoDefaultsInProductionException::class);
        $this->expectExceptionMessage('admin auth tokens');

        $this->config(
            production: true,
            dbPassword: 's3cret',
            tokens: 'u=user',
            adminTokens: WalletAuthConfig::DEMO_ADMIN_TOKENS,
        )->assertProductionSafe();
    }

    #[Test]
    public function production_boots_with_all_real_secrets(): void
    {
        $this->config(
            production: true,
            dbPassword: 's3cret',
            tokens: 'u=user',
            adminTokens: 'a=admin',
        )->assertProductionSafe();

        $this->expectNotToPerformAssertions();
    }

    private function config(
        bool $production,
        string $dbPassword = 'wallet',
        string $tokens = WalletAuthConfig::DEMO_TOKENS,
        string $adminTokens = WalletAuthConfig::DEMO_ADMIN_TOKENS,
    ): WalletConfig {
        return new WalletConfig(
            http: new WalletHttpConfig('0.0.0.0', 8080, 4),
            db: new WalletDbConfig('pdo_pgsql', 'db', 5432, 'wallet', 'wallet', $dbPassword),
            auth: new WalletAuthConfig($tokens, $adminTokens),
            production: $production,
        );
    }
}
