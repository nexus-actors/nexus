<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Override;

/**
 * @psalm-api
 *
 * Test double for EnvelopedCommandBus. Records plain commands separately
 * from pre-enveloped dispatches so tests can assert both channels.
 */
final class RecordingEnvelopedCommandBus implements EnvelopedCommandBus
{
    /** @var list<Command> */
    private array $recorded = [];

    /** @var list<Envelope<Command>> */
    private array $recordedEnvelopes = [];

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $this->recorded[] = $command;
    }

    /**
     * @param Envelope<Command> $envelope
     */
    #[Override]
    public function dispatchEnveloped(Envelope $envelope): void
    {
        $this->recordedEnvelopes[] = $envelope;
    }

    /** @return list<Command> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /** @return list<Envelope<Command>> */
    public function recordedEnvelopes(): array
    {
        return $this->recordedEnvelopes;
    }
}
