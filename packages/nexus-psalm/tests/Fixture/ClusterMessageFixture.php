<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Serialization\MessageType;
use Monadial\Nexus\WorkerPool\WorkerActorRef;

#[MessageType('test.registered')]
final readonly class RegisteredMessage {}

final readonly class UnregisteredMessage {}

final class ClusterMessageFixture
{
    /** @param WorkerActorRef<RegisteredMessage> $ref */
    public function tellWithRegisteredMessage(WorkerActorRef $ref): void
    {
        $ref->tell(new RegisteredMessage());
    }

    /** @param WorkerActorRef<UnregisteredMessage> $ref */
    public function tellWithUnregisteredMessage(WorkerActorRef $ref): void
    {
        $ref->tell(new UnregisteredMessage());
    }
}
