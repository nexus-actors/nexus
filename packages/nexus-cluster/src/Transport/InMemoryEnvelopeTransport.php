<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Transport;

use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;
use Override;
use RuntimeException;

/**
 * @psalm-api
 *
 * In-memory envelope transport for unit testing. Records sent envelopes
 * and allows simulating incoming envelopes via deliver().
 */
final class InMemoryEnvelopeTransport implements EnvelopeTransport
{
    /** @var list<array{targetWorker: int, envelope: Envelope}> */
    private array $sent = [];

    /** @var list<Envelope> */
    private array $inbox = [];

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        $this->sent[] = ['envelope' => $envelope, 'targetWorker' => $targetWorker];
    }

    #[Override]
    #[NoDiscard]
    public function receive(): Envelope
    {
        if ($this->inbox === []) {
            throw new RuntimeException('No envelopes available in inbox');
        }

        return array_shift($this->inbox);
    }

    #[Override]
    public function close(): void
    {
        $this->sent = [];
        $this->inbox = [];
    }

    /**
     * Simulate an incoming envelope from a remote worker.
     */
    public function deliver(Envelope $envelope): void
    {
        $this->inbox[] = $envelope;
    }

    /**
     * Returns all envelopes sent through this transport.
     *
     * @return list<array{targetWorker: int, envelope: Envelope}>
     */
    #[NoDiscard]
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Returns all envelopes sent to a specific worker.
     *
     * @return list<Envelope>
     */
    #[NoDiscard]
    public function getSentTo(int $workerId): array
    {
        $envelopes = [];

        foreach ($this->sent as $entry) {
            if ($entry['targetWorker'] === $workerId) {
                $envelopes[] = $entry['envelope'];
            }
        }

        return $envelopes;
    }
}
