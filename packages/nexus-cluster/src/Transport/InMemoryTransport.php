<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Transport;

use Closure;
use Override;

/**
 * @psalm-api
 *
 * In-memory transport for unit testing. Records sent messages
 * and allows simulating incoming messages via receive().
 */
final class InMemoryTransport implements Transport
{
    /** @var list<array{targetWorker: int, data: string}> */
    private array $sent = [];

    /** @var ?Closure(string): void */
    private ?Closure $listener = null;

    #[Override]
    public function send(int $targetWorker, string $data): void
    {
        $this->sent[] = ['targetWorker' => $targetWorker, 'data' => $data];
    }

    #[Override]
    public function listen(callable $onMessage): void
    {
        $this->listener = $onMessage(...);
    }

    #[Override]
    public function close(): void
    {
        $this->listener = null;
    }

    /**
     * Simulate receiving a message from the transport.
     * Triggers the registered listener if one exists.
     */
    public function receive(string $data): void
    {
        if ($this->listener !== null) {
            ($this->listener)($data);
        }
    }

    /**
     * Returns all messages sent through this transport.
     *
     * @return list<array{targetWorker: int, data: string}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Returns all messages sent to a specific worker.
     *
     * @return list<string>
     */
    public function getSentTo(int $workerId): array
    {
        $messages = [];

        foreach ($this->sent as $entry) {
            if ($entry['targetWorker'] === $workerId) {
                $messages[] = $entry['data'];
            }
        }

        return $messages;
    }
}
