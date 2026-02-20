<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Override;

final readonly class GoodMsg {}

/**
 * Good: readonly class — no mutable state.
 *
 * @implements ActorHandler<GoodMsg>
 */
final readonly class GoodActorHandler implements ActorHandler
{
    public function __construct(private string $name) {}

    /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

/**
 * Bad: public mutable property on actor handler.
 *
 * @implements ActorHandler<GoodMsg>
 */
final class BadActorHandler implements ActorHandler
{
    public int $count = 0;

    /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        $this->count++;

        return Behavior::same();
    }
}

/**
 * OK: private mutable property is fine.
 *
 * @implements ActorHandler<GoodMsg>
 */
final class PrivateMutableActorHandler implements ActorHandler
{
    private int $count = 0;

    /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        $this->count++;

        return Behavior::same();
    }
}

/**
 * OK: public readonly property is fine.
 *
 * @implements ActorHandler<GoodMsg>
 */
final class PublicReadonlyActorHandler implements ActorHandler
{
    public function __construct(public readonly string $name) {}

    /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

/** Not an actor handler — should not be checked. */
final class RegularClassWithMutableProperty
{
    public int $value = 0;
}
