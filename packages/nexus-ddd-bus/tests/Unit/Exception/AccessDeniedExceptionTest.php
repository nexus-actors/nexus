<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(AccessDeniedException::class)]
final class AccessDeniedExceptionTest extends TestCase
{
    #[Test]
    public function forStringSubjectWithoutPrincipal(): void
    {
        $ex = AccessDeniedException::for('order.place', 'order-42');

        self::assertInstanceOf(DomainException::class, $ex);
        self::assertInstanceOf(TerminalFailure::class, $ex);
        self::assertStringContainsString('order.place', $ex->getMessage());
        self::assertStringContainsString('order-42', $ex->getMessage());
        self::assertStringNotContainsString('principal=', $ex->getMessage());
    }

    #[Test]
    public function forObjectSubjectRendersDebugType(): void
    {
        $ex = AccessDeniedException::for('order.place', new stdClass());

        self::assertStringContainsString('stdClass', $ex->getMessage());
    }

    #[Test]
    public function forWithPrincipalDoesNotLeakIdInMessage(): void
    {
        $principal = new class implements Principal {
            #[Override]
            public function id(): string
            {
                return 'user-7';
            }
        };

        $ex = AccessDeniedException::for('order.place', 'order-42', $principal);

        self::assertStringNotContainsString('user-7', $ex->getMessage());
        self::assertStringNotContainsString('principal=', $ex->getMessage());
    }

    #[Test]
    public function principalAccessorReturnsSomeWhenSupplied(): void
    {
        $principal = new class implements Principal {
            #[Override]
            public function id(): string
            {
                return 'user-7';
            }
        };

        $ex = AccessDeniedException::for('order.place', 'order-42', $principal);

        self::assertTrue($ex->principal()->isSome());
        self::assertSame($principal, $ex->principal()->getUnsafe());
    }

    #[Test]
    public function principalAccessorReturnsNoneWhenAbsent(): void
    {
        $ex = AccessDeniedException::for('order.place', 'order-42');

        self::assertTrue($ex->principal()->isNone());
    }
}
