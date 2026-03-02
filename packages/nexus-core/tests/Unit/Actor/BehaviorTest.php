<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorTag;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Behavior::class)]
final class BehaviorTest extends TestCase
{
    #[Test]
    public function receiveCreatesBehaviorWithReceiveTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);

        self::assertSame(BehaviorTag::Receive, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertSame($handler, $behavior->handler());
    }

    #[Test]
    public function withStateCreatesBehaviorWithStateTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $handler = static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::same();
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $behavior = Behavior::withState(42, $handler);

        self::assertSame(BehaviorTag::WithState, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertSame($handler, $behavior->handler());
        self::assertNotNull($behavior->initialState());
        self::assertSame(42, $behavior->initialState());
    }

    #[Test]
    public function setupCreatesBehaviorWithSetupTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $factory = static fn(ActorContext $ctx): Behavior => Behavior::same();
        $behavior = Behavior::setup($factory);

        self::assertSame(BehaviorTag::Setup, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertSame($factory, $behavior->handler());
    }

    #[Test]
    public function sameCreatesBehaviorWithSameTag(): void
    {
        $behavior = Behavior::same();

        self::assertSame(BehaviorTag::Same, $behavior->tag());
        self::assertTrue($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertFalse($behavior->isUnhandled());
        self::assertNull($behavior->handler());
    }

    #[Test]
    public function stoppedCreatesBehaviorWithStoppedTag(): void
    {
        $behavior = Behavior::stopped();

        self::assertSame(BehaviorTag::Stopped, $behavior->tag());
        self::assertTrue($behavior->isStopped());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isUnhandled());
        self::assertNull($behavior->handler());
    }

    #[Test]
    public function unhandledCreatesBehaviorWithUnhandledTag(): void
    {
        $behavior = Behavior::unhandled();

        self::assertSame(BehaviorTag::Unhandled, $behavior->tag());
        self::assertTrue($behavior->isUnhandled());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertNull($behavior->handler());
    }

    #[Test]
    public function emptyCreatesBehaviorWithEmptyTag(): void
    {
        $behavior = Behavior::empty();

        self::assertSame(BehaviorTag::Empty, $behavior->tag());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertFalse($behavior->isUnhandled());
        self::assertNull($behavior->handler());
    }

    #[Test]
    public function onSignalReturnsNewBehaviorWithSignalHandler(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        /** @psalm-suppress UnusedClosureParam */
        $signalHandler = static fn(ActorContext $ctx, Signal $sig): Behavior => Behavior::stopped();

        $original = Behavior::receive($handler);
        $withSignal = $original->onSignal($signalHandler);

        // Original is unchanged (immutability)
        self::assertNull($original->signalHandler());

        // New behavior has the signal handler
        self::assertNotNull($withSignal->signalHandler());
        self::assertSame($signalHandler, $withSignal->signalHandler());

        // Tag and handler are preserved
        self::assertSame(BehaviorTag::Receive, $withSignal->tag());
        self::assertSame($handler, $withSignal->handler());
    }

    #[Test]
    public function onSignalPreservesInitialState(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $handler = static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::same();
        /** @psalm-suppress UnusedClosureParam */
        $signalHandler = static fn(ActorContext $ctx, Signal $sig): Behavior => Behavior::stopped();

        /** @psalm-suppress MixedArgumentTypeCoercion */
        $behavior = Behavior::withState(99, $handler)->onSignal($signalHandler);

        self::assertSame(BehaviorTag::WithState, $behavior->tag());
        self::assertNotNull($behavior->initialState());
        self::assertSame(99, $behavior->initialState());
        self::assertNotNull($behavior->signalHandler());
    }

    #[Test]
    public function tagAccessorsReturnCorrectValues(): void
    {
        self::assertSame(BehaviorTag::Receive, Behavior::receive(static fn() => Behavior::same())->tag());
        self::assertSame(BehaviorTag::Same, Behavior::same()->tag());
        self::assertSame(BehaviorTag::Stopped, Behavior::stopped()->tag());
        self::assertSame(BehaviorTag::Unhandled, Behavior::unhandled()->tag());
        self::assertSame(BehaviorTag::Empty, Behavior::empty()->tag());
        self::assertSame(BehaviorTag::Setup, Behavior::setup(static fn() => Behavior::same())->tag());
    }

    #[Test]
    public function withTimersCreatesBehaviorWithTimersTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $factory = static fn(object $timers): Behavior => Behavior::same();
        $behavior = Behavior::withTimers($factory);

        self::assertSame(BehaviorTag::WithTimers, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertSame($factory, $behavior->handler());
        self::assertNull($behavior->initialState());
    }

    #[Test]
    public function withStashCreatesBehaviorWithStashTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $factory = static fn(object $stash): Behavior => Behavior::same();
        $behavior = Behavior::withStash(100, $factory);

        self::assertSame(BehaviorTag::WithStash, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertSame($factory, $behavior->handler());
        self::assertNotNull($behavior->initialState());
        self::assertSame(100, $behavior->initialState());
    }

    #[Test]
    public function superviseCreatesBehaviorWithSupervisedTag(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        self::assertSame(BehaviorTag::Supervised, $behavior->tag());
        self::assertNotNull($behavior->handler());
        self::assertNotNull($behavior->initialState());
        self::assertSame($strategy, $behavior->initialState());
    }

    #[Test]
    public function superviseHandlerReturnsInnerBehavior(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        $provider = $behavior->handler();
        /** @psalm-suppress PossiblyNullFunctionCall */
        $resolved = $provider();
        self::assertSame($inner, $resolved);
    }
}
