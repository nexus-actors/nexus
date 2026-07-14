<?php

declare(strict_types=1);

namespace App\Message;

use Monadial\Nexus\Core\Actor\ActorRef;

readonly class Ping
{
    /** @param ActorRef<Pong> $replyTo */
    public function __construct(public ActorRef $replyTo) {}
}
