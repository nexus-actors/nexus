<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BehaviorWithState::class)]
final class BehaviorWithStateTest extends TestCase
{
    #[Test]
    public function nextKeepsBehaviorButUpdatesState(): void
    {
        $result = BehaviorWithState::next(100);

        self::assertTrue($result->hasNewState());
        self::assertSame(100, $result->state());
        self::assertNull($result->behavior());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function sameKeepsBothBehaviorAndState(): void
    {
        $result = BehaviorWithState::same();

        self::assertFalse($result->hasNewState());
        self::assertNull($result->behavior());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function stoppedStopsTheActor(): void
    {
        $result = BehaviorWithState::stopped();

        self::assertTrue($result->isStopped());
        self::assertFalse($result->hasNewState());
        self::assertNull($result->behavior());
    }

    #[Test]
    public function withBehaviorSwitchesBothBehaviorAndState(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $newBehavior = Behavior::receive($handler);
        $result = BehaviorWithState::withBehavior($newBehavior, 'new-state');

        self::assertNotNull($result->behavior());
        self::assertSame($newBehavior, $result->behavior());
        self::assertTrue($result->hasNewState());
        self::assertSame('new-state', $result->state());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function stateAccessorReturnsValue(): void
    {
        $withState = BehaviorWithState::next(['key' => 'value']);

        self::assertTrue($withState->hasNewState());
        self::assertSame(['key' => 'value'], $withState->state());

        $withoutState = BehaviorWithState::same();

        self::assertFalse($withoutState->hasNewState());
    }

    #[Test]
    public function behaviorAccessorReturnsNullableValue(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);
        $withBehavior = BehaviorWithState::withBehavior($behavior, 0);

        self::assertSame($behavior, $withBehavior->behavior());

        $withoutBehavior = BehaviorWithState::next(42);

        self::assertNull($withoutBehavior->behavior());
    }
}
