<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Override;

/**
 * Test fixture: an `Outbox` that records each method invocation as a
 * tuple. Tests assert call counts and ordering against the public arrays.
 *
 * @psalm-api
 */
final class RecordingOutbox implements Outbox
{
    /** @var list<array{command: Command, producerId: Option<MessageId>}> */
    public array $appendCommandCalls = [];

    /** @var list<array{event: DomainEvent, producerId: Option<MessageId>}> */
    public array $appendEventCalls = [];

    public int $flushCalls = 0;

    public int $discardCalls = 0;

    /** @param Option<MessageId> $producerId */
    #[Override]
    public function appendCommand(Command $command, Option $producerId): void
    {
        $this->appendCommandCalls[] = ['command' => $command, 'producerId' => $producerId];
    }

    /** @param Option<MessageId> $producerId */
    #[Override]
    public function appendEvent(DomainEvent $event, Option $producerId): void
    {
        $this->appendEventCalls[] = ['event' => $event, 'producerId' => $producerId];
    }

    #[Override]
    public function flush(): void
    {
        $this->flushCalls++;
    }

    #[Override]
    public function discard(): void
    {
        $this->discardCalls++;
    }
}
