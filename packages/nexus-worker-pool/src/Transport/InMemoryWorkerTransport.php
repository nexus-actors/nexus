<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Transport;

use Closure;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Override;

/** @psalm-api */
final class InMemoryWorkerTransport implements WorkerTransport
{
    /** @var list<array{targetWorker: int, envelope: Envelope}> */
    private array $sent = [];

    /** @var ?Closure(Envelope): void */
    private ?Closure $listener = null;

    private bool $stopping = false;

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        $this->sent[] = ['envelope' => $envelope, 'targetWorker' => $targetWorker];
    }

    #[Override]
    public function listen(callable $onEnvelope): void
    {
        $this->listener = $onEnvelope(...);
    }

    #[Override]
    public function close(): void
    {
        $this->listener = null;
    }

    #[Override]
    public function stop(): void
    {
        $this->stopping = true;
    }

    #[Override]
    public function isStopped(): bool
    {
        return $this->stopping;
    }

    /**
     * Simulate receiving an envelope (for testing).
     */
    public function receive(Envelope $envelope): void
    {
        if ($this->listener !== null) {
            ($this->listener)($envelope);
        }
    }

    /**
     * @return list<Envelope>
     */
    public function getSentTo(int $workerId): array
    {
        $result = [];

        foreach ($this->sent as $entry) {
            if ($entry['targetWorker'] === $workerId) {
                $result[] = $entry['envelope'];
            }
        }

        return $result;
    }

    /**
     * @return list<array{targetWorker: int, envelope: Envelope}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }
}
