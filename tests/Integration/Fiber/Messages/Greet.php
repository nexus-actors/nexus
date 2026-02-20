<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Messages;

use Monadial\Nexus\Core\Actor\ActorRef;

/** @psalm-api */
final readonly class Greet
{
    /** @param ActorRef<object> $replyTo */
    public function __construct(public string $name, public ActorRef $replyTo) {}
}
