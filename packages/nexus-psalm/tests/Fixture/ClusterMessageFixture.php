<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('test.registered')]
final readonly class RegisteredMessage {
}

final readonly class UnregisteredMessage {
}

final class ClusterMessageFixture
{
    /** @param RemoteActorRef<RegisteredMessage> $ref */
    public function tellWithRegisteredMessage(RemoteActorRef $ref): void
    {
        $ref->tell(new RegisteredMessage());
    }

    /** @param RemoteActorRef<UnregisteredMessage> $ref */
    public function tellWithUnregisteredMessage(RemoteActorRef $ref): void
    {
        $ref->tell(new UnregisteredMessage());
    }
}
