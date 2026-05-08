<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Override;

/**
 * @psalm-api
 *
 * Test double for EventBus. Records every published event so tests can
 * assert what was broadcast without a real bus implementation.
 */
final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    private array $recorded = [];

    #[Override]
    public function publishEvent(DomainEvent $event): void
    {
        $this->recorded[] = $event;
    }

    /** @return list<DomainEvent> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
