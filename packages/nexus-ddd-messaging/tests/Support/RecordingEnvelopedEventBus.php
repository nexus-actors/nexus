<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Test double for EnvelopedEventBus. Records plain events separately from
 * pre-enveloped publications so tests can assert both channels.
 */
final class RecordingEnvelopedEventBus implements EnvelopedEventBus
{
    /** @var list<DomainEvent> */
    private array $recorded = [];

    /** @var list<Envelope<DomainEvent>> */
    private array $recordedEnvelopes = [];

    #[Override]
    public function publishEvent(DomainEvent $event): void
    {
        $this->recorded[] = $event;
    }

    /**
     * @param Envelope<DomainEvent> $envelope
     */
    #[Override]
    public function publishEnveloped(Envelope $envelope): void
    {
        $this->recordedEnvelopes[] = $envelope;
    }

    /** @return list<DomainEvent> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /** @return list<Envelope<DomainEvent>> */
    public function recordedEnvelopes(): array
    {
        return $this->recordedEnvelopes;
    }
}
