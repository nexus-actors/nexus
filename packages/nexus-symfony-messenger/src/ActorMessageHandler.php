<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger;

use Monadial\Nexus\Core\Actor\ActorRef;

final class ActorMessageHandler
{
    public function __construct(private readonly ActorRef $ref) {}

    public function dispatch(object $message): void
    {
        $this->ref->tell($message);
    }
}
