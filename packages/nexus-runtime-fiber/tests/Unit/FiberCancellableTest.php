<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Runtime\Fiber\FiberCancellable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FiberCancellable::class)]
final class FiberCancellableTest extends TestCase
{
    #[Test]
    public function it_implements_cancellable(): void
    {
        $cancellable = new FiberCancellable();
        self::assertInstanceOf(Cancellable::class, $cancellable);
    }

    #[Test]
    public function it_is_not_cancelled_initially(): void
    {
        $cancellable = new FiberCancellable();
        self::assertFalse($cancellable->isCancelled());
    }

    #[Test]
    public function cancel_sets_cancelled_state(): void
    {
        $cancellable = new FiberCancellable();
        $cancellable->cancel();
        self::assertTrue($cancellable->isCancelled());
    }

    #[Test]
    public function double_cancel_is_idempotent(): void
    {
        $cancellable = new FiberCancellable();
        $cancellable->cancel();
        $cancellable->cancel();
        self::assertTrue($cancellable->isCancelled());
    }
}
