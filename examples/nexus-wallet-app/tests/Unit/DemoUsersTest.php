<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Tests\Unit;

use Monadial\Nexus\Example\Wallet\Boot\WalletAuthConfig;
use Monadial\Nexus\Example\Wallet\Http\Auth\DemoUsers;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SEC-013: user and admin token lists must mint disjoint privilege sets so an
 * admin capability can never be issued from the user list.
 */
final class DemoUsersTest extends TestCase
{
    #[Test]
    public function user_token_mints_user_role_without_admin_privileges(): void
    {
        $auth = DemoUsers::fromConfig(new WalletAuthConfig('alice-token=alice', 'admin-token=root'));

        $principal = $auth->authenticate($this->bearer('alice-token'));

        self::assertNotNull($principal);
        self::assertSame('alice', $principal->id());
        self::assertTrue($principal->hasRole('user'));
        self::assertFalse($principal->hasRole('admin'));
        self::assertNotContains('wallet:admin', $principal->scopes());
    }

    #[Test]
    public function admin_token_mints_admin_role_with_admin_scope(): void
    {
        $auth = DemoUsers::fromConfig(new WalletAuthConfig('alice-token=alice', 'admin-token=root'));

        $principal = $auth->authenticate($this->bearer('admin-token'));

        self::assertNotNull($principal);
        self::assertSame('root', $principal->id());
        self::assertTrue($principal->hasRole('admin'));
        self::assertContains('wallet:admin', $principal->scopes());
    }

    #[Test]
    public function unknown_token_is_rejected(): void
    {
        $auth = DemoUsers::fromConfig(new WalletAuthConfig('alice-token=alice', 'admin-token=root'));

        self::assertNull($auth->authenticate($this->bearer('nope')));
    }

    private function bearer(string $token): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/admin/wallets'))
            ->withHeader('Authorization', "Bearer {$token}");
    }
}
