<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\WithStateBehavior;

/**
 * Counter command message used by the stateful handlers below.
 */
final readonly class FixtureIncrement
{
    public function __construct(public int $by = 1) {}
}

final class BehaviorWithStateInferenceFixture
{
    /**
     * Both closure params typed → hook should infer
     * WithStateBehavior<FixtureIncrement, int>. Without the hook, this is
     * WithStateBehavior<object, mixed> and the declared return type wouldn't
     * match.
     *
     * @return WithStateBehavior<FixtureIncrement, int>
     */
    public function fullyTypedClosureReturnsBothGenerics(): WithStateBehavior
    {
        return Behavior::withState(
            0,
            static fn(ActorContext $ctx, FixtureIncrement $msg, int $count): BehaviorWithState => BehaviorWithState::same(),
        );
    }

    /**
     * Typed state, untyped (object) message — hook should still narrow the
     * state generic but leave the message as `object`. Declared return type
     * matches: WithStateBehavior<object, int>.
     *
     * @return WithStateBehavior<object, int>
     */
    public function objectMessageWithTypedStateReturnsPartialGeneric(): WithStateBehavior
    {
        return Behavior::withState(
            0,
            static fn(ActorContext $ctx, object $msg, int $count): BehaviorWithState => BehaviorWithState::same(),
        );
    }

    /**
     * Typed message but the declared return type uses the WRONG state
     * generic — Psalm SHOULD report InvalidReturnStatement because the
     * hook narrows the actual return to WithStateBehavior<FixtureIncrement, int>.
     *
     * @return WithStateBehavior<FixtureIncrement, string>
     */
    public function typedClosureWithMismatchedStateReturnFails(): WithStateBehavior
    {
        return Behavior::withState(
            0,
            static fn(ActorContext $ctx, FixtureIncrement $msg, int $count): BehaviorWithState => BehaviorWithState::same(),
        );
    }
}
