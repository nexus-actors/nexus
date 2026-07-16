<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Override;

use function sleep;

final readonly class BlockingFixtureMsg {}

/**
 * Bad: calls sleep() inside actor handler.
 *
 * @implements ActorHandler<BlockingFixtureMsg>
 */
final readonly class BlockingActorHandler implements ActorHandler
{
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        sleep(1);

        return Behavior::same();
    }
}

/**
 * Good: no blocking calls.
 *
 * @implements ActorHandler<BlockingFixtureMsg>
 */
final readonly class NonBlockingActorHandler implements ActorHandler
{
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

/** Not an actor — blocking is fine here. */
final class RegularClassWithSleep
{
    public function doWork(): void
    {
        sleep(1);
    }
}
