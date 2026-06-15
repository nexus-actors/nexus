<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Principal;

use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimplePrincipal::class)]
final class SimplePrincipalTest extends TestCase
{
    #[Test]
    public function exposes_id_roles_scopes_and_claims(): void
    {
        $p = new SimplePrincipal(
            id: 'user-42',
            roles: ['admin', 'staff'],
            scopes: ['orders.read', 'orders.write'],
            claims: ['email' => 'a@b.co'],
        );

        self::assertSame('user-42', $p->id());
        self::assertSame(['admin', 'staff'], $p->roles());
        self::assertSame(['orders.read', 'orders.write'], $p->scopes());
        self::assertSame(['email' => 'a@b.co'], $p->claims());
    }

    #[Test]
    public function has_role_returns_true_only_for_present_roles(): void
    {
        $p = new SimplePrincipal('u', roles: ['admin']);

        self::assertTrue($p->hasRole('admin'));
        self::assertFalse($p->hasRole('staff'));
    }

    #[Test]
    public function has_scope_returns_true_only_for_present_scopes(): void
    {
        $p = new SimplePrincipal('u', scopes: ['orders.read']);

        self::assertTrue($p->hasScope('orders.read'));
        self::assertFalse($p->hasScope('orders.write'));
    }

    #[Test]
    public function defaults_to_empty_collections(): void
    {
        $p = new SimplePrincipal('u');

        self::assertSame([], $p->roles());
        self::assertSame([], $p->scopes());
        self::assertSame([], $p->claims());
    }
}
