<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Staging;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * In-memory staging — TESTS-ONLY (and single-process Fiber-only).
 *
 * Provides at-most-once delivery: a crash between flush() start and bus
 * dispatch loses messages. Production deployments MUST use a persistent
 * staging implementation. The runtime warning logged on every flush() is
 * the operator-facing canary that this is wired in production.
 */
final class InMemoryMessageStaging implements MessageStaging
{
    /** @var list<Envelope<Command>> */
    private array $commandEnvelopes = [];

    /** @var list<Envelope<DomainEvent>> */
    private array $eventEnvelopes = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EnvelopedCommandBus $commandBus,
        private readonly EnvelopedEventBus $eventBus,
        private readonly MessageContextStack $stack,
        private readonly ClockInterface $clock,
        private readonly NodeId $nodeId,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param Option<MessageId> $producerId
     */
    #[Override]
    public function appendCommand(Command $command, Option $producerId): void
    {
        $this->commandEnvelopes[] = new Envelope($command, $this->buildMetadata($producerId));
    }

    /**
     * @param Option<MessageId> $producerId
     */
    #[Override]
    public function appendEvent(DomainEvent $event, Option $producerId): void
    {
        $this->eventEnvelopes[] = new Envelope($event, $this->buildMetadata($producerId));
    }

    #[Override]
    public function flush(): void
    {
        $this->logger->warning(
            'InMemoryMessageStaging.flush() — at-most-once delivery; '
            . 'a crash between flush() start and bus dispatch loses messages. '
            . 'Use a persistent staging implementation in production.',
        );

        foreach ($this->commandEnvelopes as $envelope) {
            $this->commandBus->dispatchEnveloped($envelope);
        }

        foreach ($this->eventEnvelopes as $envelope) {
            $this->eventBus->publishEnveloped($envelope);
        }

        $this->commandEnvelopes = [];
        $this->eventEnvelopes = [];
    }

    #[Override]
    public function discard(): void
    {
        $this->commandEnvelopes = [];
        $this->eventEnvelopes = [];
    }

    /**
     * @param Option<MessageId> $producerId
     */
    private function buildMetadata(Option $producerId): MessageMetadata
    {
        $id = $producerId->getOrCall(static fn(): MessageId => MessageId::generate());
        $now = $this->clock->now();
        $nodeId = $this->nodeId;

        return $this->stack->current()
            ->map(fn(MessageContext $parent): MessageMetadata => $parent->metadata->forCausedMessage($id, $now, $nodeId))
            ->getOrCall(fn(): MessageMetadata => MessageMetadata::root($this->clock, $this->nodeId)->forCausedMessage($id, $now, $this->nodeId));
    }
}
