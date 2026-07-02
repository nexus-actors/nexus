<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ReceiveBehavior;

/**
 * Message used by the receive() handlers below. A plain readonly DTO —
 * BehaviorReceiveReturnTypeProvider should resolve the closure's second
 * parameter (the message) to this class and rewrite Behavior::receive(...)
 * to ReceiveBehavior<FixtureGreet>.
 */
final readonly class FixtureGreet
{
    public function __construct(public string $name) {}
}

/** Used only as a target for the mismatch test below. */
final readonly class FixtureUnrelated
{
    public function __construct(public string $payload) {}
}

final class BehaviorReceiveInferenceFixture
{
    /**
     * Typed closure → hook should infer ReceiveBehavior<FixtureGreet>. If
     * the hook is broken, Psalm reports ReceiveBehavior<object> here and
     * the declared return type doesn't match.
     *
     * @return ReceiveBehavior<FixtureGreet>
     */
    public function typedClosureReturnsTypedReceive(): ReceiveBehavior
    {
        return Behavior::receive(
            static fn(ActorContext $ctx, FixtureGreet $msg): Behavior => Behavior::same(),
        );
    }

    /**
     * Untyped (just `object`) closure → hook falls through (the early-skip
     * for "object" param), Psalm's default applies and returns
     * ReceiveBehavior<object>. The @psalm-trace pins the literal resolved
     * type so a regression where the hook eagerly returns the wrong generic
     * (e.g., ReceiveBehavior<never>) gets caught — a passing covariant
     * return-type check alone is not strong enough.
     *
     * @return ReceiveBehavior<object>
     */
    public function untypedClosureReturnsObjectReceive(): ReceiveBehavior
    {
        $b = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        /** @psalm-trace $b */
        return $b;
    }

    /**
     * Typed closure but declared return is the WRONG generic. Psalm should
     * complain — proves the hook is actively re-typing the call (rather
     * than passing through, which would silently allow this).
     *
     * @return ReceiveBehavior<FixtureUnrelated>
     */
    public function typedClosureWithMismatchedReturnFails(): ReceiveBehavior
    {
        return Behavior::receive(
            static fn(ActorContext $ctx, FixtureGreet $msg): Behavior => Behavior::same(),
        );
    }
}
