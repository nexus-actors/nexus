<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class Unwatch implements SystemMessage
{
    /**
     * @param ActorRef<object> $watcher
     */
    public function __construct(
        public ActorRef $watcher,
    ) {}
}
