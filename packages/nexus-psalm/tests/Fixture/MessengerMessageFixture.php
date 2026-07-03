<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('test.messenger.registered')]
final readonly class RegisteredMessengerMessage {}

final readonly class UnregisteredMessengerMessage {}

final class MessengerMessageFixture
{
    /** @param MessengerActorRef<RegisteredMessengerMessage> $ref */
    public function tellWithRegisteredMessage(MessengerActorRef $ref): void
    {
        $ref->tell(new RegisteredMessengerMessage());
    }

    /** @param MessengerActorRef<UnregisteredMessengerMessage> $ref */
    public function tellWithUnregisteredMessage(MessengerActorRef $ref): void
    {
        $ref->tell(new UnregisteredMessengerMessage());
    }
}
