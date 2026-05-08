<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Staging;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/**
 * @psalm-api
 *
 * Buffer for messages a domain object (PM, aggregate) wants to dispatch
 * after the surrounding transaction commits.
 */
interface MessageStaging
{
    /**
     * @param Option<MessageId> $producerId Caller-supplied id; if none, the
     *        staging generates a fresh one. Producer-supplied ids enable
     *        crash-replay safety (deterministic ids across retries).
     */
    public function appendCommand(Command $command, Option $producerId): void;

    /**
     * @param Option<MessageId> $producerId
     */
    public function appendEvent(DomainEvent $event, Option $producerId): void;

    public function flush(): void;

    public function discard(): void;
}
