<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorState;

final class InvalidActorStateTransition extends NexusLogicException
{
    public function __construct(
        public readonly ActorState $from,
        public readonly ActorState $to,
    ) {
        parent::__construct(
            "Invalid actor state transition: {$from->value} → {$to->value}",
        );
    }
}
