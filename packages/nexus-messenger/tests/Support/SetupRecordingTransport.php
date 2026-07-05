<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Test transport recording setup() and reset() invocations.
 */
final class SetupRecordingTransport implements SetupableTransportInterface, TransportInterface
{
    public int $setupCalls = 0;
    public int $resetCalls = 0;

    #[Override]
    public function get(): iterable
    {
        return [];
    }

    #[Override]
    public function ack(Envelope $envelope): void
    {
        // no-op: only setup()/reset() calls are recorded
    }

    #[Override]
    public function reject(Envelope $envelope): void
    {
        // no-op: only setup()/reset() calls are recorded
    }

    #[Override]
    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }

    #[Override]
    public function setup(): void
    {
        ++$this->setupCalls;
    }

    public function reset(): void
    {
        ++$this->resetCalls;
    }
}
