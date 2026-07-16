<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\EmptyBehavior;
use Monadial\Nexus\Core\Actor\ReceiveBehavior;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\SetupBehavior;
use Monadial\Nexus\Core\Actor\StoppedBehavior;
use Monadial\Nexus\Core\Actor\SupervisedBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Core\Actor\WithStashBehavior;
use Monadial\Nexus\Core\Actor\WithStateBehavior;
use Monadial\Nexus\Core\Actor\WithTimersBehavior;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Behavior::class)]
#[CoversClass(ReceiveBehavior::class)]
#[CoversClass(WithStateBehavior::class)]
#[CoversClass(SetupBehavior::class)]
#[CoversClass(SameBehavior::class)]
#[CoversClass(StoppedBehavior::class)]
#[CoversClass(UnhandledBehavior::class)]
#[CoversClass(EmptyBehavior::class)]
#[CoversClass(WithTimersBehavior::class)]
#[CoversClass(WithStashBehavior::class)]
#[CoversClass(SupervisedBehavior::class)]
final class BehaviorTest extends TestCase
{
    #[Test]
    public function receiveCreatesBehaviorWithReceiveTag(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);

        self::assertInstanceOf(ReceiveBehavior::class, $behavior);
        self::assertSame($handler, $behavior->handler);
    }

    #[Test]
    public function withStateCreatesBehaviorWithStateTag(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::same();
        $behavior = Behavior::withState(42, $handler);

        self::assertInstanceOf(WithStateBehavior::class, $behavior);
        self::assertSame($handler, $behavior->handler);
        self::assertSame(42, $behavior->initialState);
    }

    #[Test]
    public function setupCreatesBehaviorWithSetupTag(): void
    {
        $factory = static fn(ActorContext $ctx): Behavior => Behavior::same();
        $behavior = Behavior::setup($factory);

        self::assertInstanceOf(SetupBehavior::class, $behavior);
        self::assertSame($factory, $behavior->factory);
    }

    #[Test]
    public function sameCreatesBehaviorWithSameTag(): void
    {
        $behavior = Behavior::same();

        self::assertInstanceOf(SameBehavior::class, $behavior);
        self::assertNull($behavior->signalHandler());
    }

    #[Test]
    public function stoppedCreatesBehaviorWithStoppedTag(): void
    {
        $behavior = Behavior::stopped();

        self::assertInstanceOf(StoppedBehavior::class, $behavior);
        self::assertNull($behavior->signalHandler());
    }

    #[Test]
    public function unhandledCreatesBehaviorWithUnhandledTag(): void
    {
        $behavior = Behavior::unhandled();

        self::assertInstanceOf(UnhandledBehavior::class, $behavior);
        self::assertNull($behavior->signalHandler());
    }

    #[Test]
    public function emptyCreatesBehaviorWithEmptyTag(): void
    {
        $behavior = Behavior::empty();

        self::assertInstanceOf(EmptyBehavior::class, $behavior);
        self::assertNull($behavior->signalHandler());
    }

    #[Test]
    public function onSignalReturnsNewBehaviorWithSignalHandler(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $signalHandler = static fn(ActorContext $ctx, Signal $sig): Behavior => Behavior::stopped();

        $original = Behavior::receive($handler);
        $withSignal = $original->onSignal($signalHandler);

        // Original is unchanged (immutability)
        self::assertNull($original->signalHandler());

        // New behavior has the signal handler
        self::assertNotNull($withSignal->signalHandler());
        self::assertSame($signalHandler, $withSignal->signalHandler());

        // Type and handler are preserved
        self::assertInstanceOf(ReceiveBehavior::class, $withSignal);
        self::assertSame($handler, $withSignal->handler);
    }

    #[Test]
    public function onSignalPreservesInitialState(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::same();
        $signalHandler = static fn(ActorContext $ctx, Signal $sig): Behavior => Behavior::stopped();

        $behavior = Behavior::withState(99, $handler)->onSignal($signalHandler);

        self::assertInstanceOf(WithStateBehavior::class, $behavior);
        self::assertSame(99, $behavior->initialState);
        self::assertNotNull($behavior->signalHandler());
    }

    #[Test]
    public function factoryMethodsReturnCorrectTypes(): void
    {
        self::assertInstanceOf(ReceiveBehavior::class, Behavior::receive(static fn() => Behavior::same()));
        self::assertInstanceOf(SameBehavior::class, Behavior::same());
        self::assertInstanceOf(StoppedBehavior::class, Behavior::stopped());
        self::assertInstanceOf(UnhandledBehavior::class, Behavior::unhandled());
        self::assertInstanceOf(EmptyBehavior::class, Behavior::empty());
        self::assertInstanceOf(SetupBehavior::class, Behavior::setup(static fn() => Behavior::same()));
    }

    #[Test]
    public function withTimersCreatesBehaviorWithTimersTag(): void
    {
        $factory = static fn(object $timers): Behavior => Behavior::same();
        $behavior = Behavior::withTimers($factory);

        self::assertInstanceOf(WithTimersBehavior::class, $behavior);
        self::assertSame($factory, $behavior->factory);
    }

    #[Test]
    public function withStashCreatesBehaviorWithStashTag(): void
    {
        $factory = static fn(object $stash): Behavior => Behavior::same();
        $behavior = Behavior::withStash(100, $factory);

        self::assertInstanceOf(WithStashBehavior::class, $behavior);
        self::assertSame($factory, $behavior->factory);
        self::assertSame(100, $behavior->capacity);
    }

    #[Test]
    public function superviseCreatesBehaviorWithSupervisedTag(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        self::assertInstanceOf(SupervisedBehavior::class, $behavior);
        self::assertSame($strategy, $behavior->strategy);
    }

    #[Test]
    public function superviseInnerBehaviorIsAccessibleDirectly(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        self::assertSame($inner, $behavior->inner);
    }
}
