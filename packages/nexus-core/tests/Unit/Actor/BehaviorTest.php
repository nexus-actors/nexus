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
        self::assertTrue($behavior->handler()->isSome());
        self::assertSame($handler, $behavior->handler()->get());
    }

    #[Test]
    public function withStateCreatesBehaviorWithStateTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $handler = static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::same();
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $behavior = Behavior::withState(42, $handler);

        self::assertSame(BehaviorTag::WithState, $behavior->tag());
        self::assertTrue($behavior->handler()->isSome());
        self::assertSame($handler, $behavior->handler()->get());
        self::assertTrue($behavior->initialState()->isSome());
        self::assertSame(42, $behavior->initialState()->get());
    }

    #[Test]
    public function setupCreatesBehaviorWithSetupTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $factory = static fn(ActorContext $ctx): Behavior => Behavior::same();
        $behavior = Behavior::setup($factory);

        self::assertSame(BehaviorTag::Setup, $behavior->tag());
        self::assertTrue($behavior->handler()->isSome());
        self::assertSame($factory, $behavior->handler()->get());
    }

    #[Test]
    public function sameCreatesBehaviorWithSameTag(): void
    {
        $behavior = Behavior::same();

        self::assertSame(BehaviorTag::Same, $behavior->tag());
        self::assertTrue($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertFalse($behavior->isUnhandled());
        self::assertTrue($behavior->handler()->isNone());
    }

    #[Test]
    public function stoppedCreatesBehaviorWithStoppedTag(): void
    {
        $behavior = Behavior::stopped();

        self::assertSame(BehaviorTag::Stopped, $behavior->tag());
        self::assertTrue($behavior->isStopped());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isUnhandled());
        self::assertTrue($behavior->handler()->isNone());
    }

    #[Test]
    public function unhandledCreatesBehaviorWithUnhandledTag(): void
    {
        $behavior = Behavior::unhandled();

        self::assertSame(BehaviorTag::Unhandled, $behavior->tag());
        self::assertTrue($behavior->isUnhandled());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertTrue($behavior->handler()->isNone());
    }

    #[Test]
    public function emptyCreatesBehaviorWithEmptyTag(): void
    {
        $behavior = Behavior::empty();

        self::assertSame(BehaviorTag::Empty, $behavior->tag());
        self::assertFalse($behavior->isSame());
        self::assertFalse($behavior->isStopped());
        self::assertFalse($behavior->isUnhandled());
        self::assertTrue($behavior->handler()->isNone());
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
        self::assertTrue($original->signalHandler()->isNone());

        // New behavior has the signal handler
        self::assertTrue($withSignal->signalHandler()->isSome());
        self::assertSame($signalHandler, $withSignal->signalHandler()->get());

        // Tag and handler are preserved
        self::assertSame(BehaviorTag::Receive, $withSignal->tag());
        self::assertSame($handler, $withSignal->handler()->get());
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
        self::assertTrue($behavior->initialState()->isSome());
        self::assertSame(99, $behavior->initialState()->get());
        self::assertTrue($behavior->signalHandler()->isSome());
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
        self::assertTrue($behavior->handler()->isSome());
        self::assertSame($factory, $behavior->handler()->get());
        self::assertTrue($behavior->initialState()->isNone());
    }

    #[Test]
    public function withStashCreatesBehaviorWithStashTag(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $factory = static fn(object $stash): Behavior => Behavior::same();
        $behavior = Behavior::withStash(100, $factory);

        self::assertSame(BehaviorTag::WithStash, $behavior->tag());
        self::assertTrue($behavior->handler()->isSome());
        self::assertSame($factory, $behavior->handler()->get());
        self::assertTrue($behavior->initialState()->isSome());
        self::assertSame(100, $behavior->initialState()->get());
    }

    #[Test]
    public function superviseCreatesBehaviorWithSupervisedTag(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        self::assertSame(BehaviorTag::Supervised, $behavior->tag());
        self::assertTrue($behavior->handler()->isSome());
        self::assertTrue($behavior->initialState()->isSome());
        self::assertSame($strategy, $behavior->initialState()->get());
    }

    #[Test]
    public function superviseHandlerReturnsInnerBehavior(): void
    {
        $inner = Behavior::receive(static fn() => Behavior::same());
        $strategy = SupervisionStrategy::oneForOne();
        $behavior = Behavior::supervise($inner, $strategy);

        $provider = $behavior->handler()->get();
        /** @psalm-suppress PossiblyNullFunctionCall */
        $resolved = $provider();
        self::assertSame($inner, $resolved);
    }
}
