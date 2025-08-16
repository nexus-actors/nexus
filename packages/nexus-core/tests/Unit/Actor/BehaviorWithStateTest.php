<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorTag;
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

        self::assertTrue($result->state()->isSome());
        self::assertSame(100, $result->state()->get());
        self::assertTrue($result->behavior()->isNone());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function sameKeepsBothBehaviorAndState(): void
    {
        $result = BehaviorWithState::same();

        self::assertTrue($result->state()->isNone());
        self::assertTrue($result->behavior()->isNone());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function stoppedStopsTheActor(): void
    {
        $result = BehaviorWithState::stopped();

        self::assertTrue($result->isStopped());
        self::assertTrue($result->state()->isNone());
        self::assertTrue($result->behavior()->isNone());
    }

    #[Test]
    public function withBehaviorSwitchesBothBehaviorAndState(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $newBehavior = Behavior::receive($handler);
        $result = BehaviorWithState::withBehavior($newBehavior, 'new-state');

        self::assertTrue($result->behavior()->isSome());
        self::assertSame($newBehavior, $result->behavior()->get());
        self::assertTrue($result->state()->isSome());
        self::assertSame('new-state', $result->state()->get());
        self::assertFalse($result->isStopped());
    }

    #[Test]
    public function stateAccessorReturnsOption(): void
    {
        $withState = BehaviorWithState::next(['key' => 'value']);
        self::assertSame(['key' => 'value'], $withState->state()->get());

        $withoutState = BehaviorWithState::same();
        self::assertTrue($withoutState->state()->isNone());
    }

    #[Test]
    public function behaviorAccessorReturnsOption(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);
        $withBehavior = BehaviorWithState::withBehavior($behavior, 0);
        self::assertSame($behavior, $withBehavior->behavior()->get());

        $withoutBehavior = BehaviorWithState::next(42);
        self::assertTrue($withoutBehavior->behavior()->isNone());
    }
}
