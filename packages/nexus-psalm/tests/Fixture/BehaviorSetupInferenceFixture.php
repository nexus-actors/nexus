<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\SetupBehavior;

/**
 * Lazy-init message used by the setup factories below.
 */
final readonly class FixtureBootstrap
{
    public function __construct(public string $configKey) {}
}

/** Used only as a target for the mismatch test below. */
final readonly class FixtureUnrelatedSetup
{
    public function __construct(public string $payload) {}
}

final class BehaviorSetupInferenceFixture
{
    /**
     * Typed ActorContext<FixtureBootstrap> on the factory closure → hook
     * should infer SetupBehavior<FixtureBootstrap>. Without the hook, this
     * resolves to SetupBehavior<object>.
     *
     * @return SetupBehavior<FixtureBootstrap>
     */
    public function typedContextReturnsTypedSetup(): SetupBehavior
    {
        return Behavior::setup(
            /** @param ActorContext<FixtureBootstrap> $ctx */
            static fn(ActorContext $ctx): Behavior => Behavior::same(),
        );
    }

    /**
     * Bare ActorContext (no generic) → hook falls through, Psalm's default
     * applies. The @psalm-trace pins the literal resolved type so a
     * regression where the hook returns a wrong generic (e.g.,
     * SetupBehavior<never>) gets caught — a passing covariant return-type
     * check alone is not strong enough.
     *
     * @return SetupBehavior<object>
     */
    public function bareContextReturnsObjectSetup(): SetupBehavior
    {
        $b = Behavior::setup(
            static fn(ActorContext $ctx): Behavior => Behavior::same(),
        );

        /** @psalm-trace $b */
        return $b;
    }

    /**
     * Typed factory but declared return is the WRONG generic. Psalm should
     * flag InvalidReturnStatement — proves the hook is actively rewriting.
     *
     * @return SetupBehavior<FixtureUnrelatedSetup>
     */
    public function typedContextWithMismatchedReturnFails(): SetupBehavior
    {
        return Behavior::setup(
            /** @param ActorContext<FixtureBootstrap> $ctx */
            static fn(ActorContext $ctx): Behavior => Behavior::same(),
        );
    }
}
