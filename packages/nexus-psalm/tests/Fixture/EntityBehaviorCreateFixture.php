<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;

/**
 * Fixtures for EntityBehaviorReturnTypeProvider tests.
 */
final readonly class FixtureOrder
{
    public function __construct(public string $id = '') {}
}

final readonly class FixtureAddLineItem
{
    public function __construct(public string $sku = '') {}
}

final readonly class FixtureUnrelatedCommand
{
    public function __construct(public string $reason = '') {}
}

final class EntityBehaviorCreateFixture
{
    /**
     * Both class-string<T> and typed closure param C → hook should infer
     * EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>. Without the
     * hook, the declared return type wouldn't match.
     *
     * @return EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>
     */
    public function fullyTypedCreateReturnsBothGenerics(): EntityBehaviorBuilder
    {
        return EntityBehavior::create(
            entityClass: FixtureOrder::class,
            id: '42',
            commandHandler: static fn(ActorContext $ctx, FixtureAddLineItem $cmd, FixtureOrder $o): EntityEffect => EntityEffect::same(),
        );
    }

    /**
     * Class-string literal present but command param typed as bare `object`.
     * Hook cannot narrow C; falls through to Psalm's default. The
     * @psalm-trace below pins the resolved type for test assertions.
     */
    public function objectCommandFallsThroughToDefault(): void
    {
        $b = EntityBehavior::create(
            entityClass: FixtureOrder::class,
            id: '42',
            commandHandler: static fn(ActorContext $ctx, object $cmd, FixtureOrder $o): EntityEffect => EntityEffect::same(),
        );
        /** @psalm-trace $b */
        ($b instanceof EntityBehaviorBuilder); // prevent UnusedVariable
    }

    /**
     * Typed command but the declared return uses the WRONG entity generic.
     * Hook rewrites to EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>;
     * Psalm MUST report InvalidReturnStatement here.
     *
     * @return EntityBehaviorBuilder<FixtureUnrelatedCommand, FixtureAddLineItem>
     */
    public function mismatchedEntityGenericFails(): EntityBehaviorBuilder
    {
        return EntityBehavior::create(
            entityClass: FixtureOrder::class,
            id: '42',
            commandHandler: static fn(ActorContext $ctx, FixtureAddLineItem $cmd, FixtureOrder $o): EntityEffect => EntityEffect::same(),
        );
    }

    /**
     * Typed entity but the declared return uses the WRONG command generic.
     * Hook rewrites to EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>;
     * Psalm MUST report InvalidReturnStatement here.
     *
     * @return EntityBehaviorBuilder<FixtureOrder, FixtureUnrelatedCommand>
     */
    public function mismatchedCommandGenericFails(): EntityBehaviorBuilder
    {
        return EntityBehavior::create(
            entityClass: FixtureOrder::class,
            id: '42',
            commandHandler: static fn(ActorContext $ctx, FixtureAddLineItem $cmd, FixtureOrder $o): EntityEffect => EntityEffect::same(),
        );
    }
}
