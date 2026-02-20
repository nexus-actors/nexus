<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadLetter implements SystemMessage
{
    /**
     * @param ActorRef<object> $sender
     * @param ActorRef<object> $recipient
     */
    public function __construct(public object $message, public ActorRef $sender, public ActorRef $recipient)
    {
    }
}
