<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Override;

final readonly class PooledConnectionMsg {}

/**
 * Bad: actor handler stores a DBAL Connection for its whole lifetime.
 *
 * @implements ActorHandler<PooledConnectionMsg>
 */
final class ActorWithDbalConnection implements ActorHandler
{
    public function __construct(private Connection $conn) {}

    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

/**
 * Bad: stateful actor handler stores an EntityManagerInterface.
 *
 * @implements StatefulActorHandler<PooledConnectionMsg, int>
 */
final class StatefulActorWithEntityManager implements StatefulActorHandler
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Override]
    public function initialState(): int
    {
        return 0;
    }

    #[Override]
    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return BehaviorWithState::same();
    }
}

/** Not an actor handler — storing Connection here is fine. */
final class RegularServiceWithConnection
{
    public function __construct(private Connection $conn) {}
}
