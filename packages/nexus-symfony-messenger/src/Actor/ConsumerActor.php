<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Messenger\Message\ConsumeFromTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Actor that polls Messenger transports and dispatches received messages onto the bus.
 *
 * Receives ConsumeFromTransport messages; polls the named transport up to $limit
 * messages per tick and dispatches each onto the injected MessageBusInterface.
 *
 * @implements ActorHandler<object>
 * @psalm-api
 */
final class ConsumerActor implements ActorHandler
{
    /**
     * @param array<string, TransportInterface> $transports
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly array $transports,
    ) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof ConsumeFromTransport) {
            return Behavior::unhandled();
        }

        $transport = $this->transports[$message->transportName] ?? null;

        if ($transport === null) {
            return Behavior::same();
        }

        /** @var array<Envelope> $envelopes */
        $envelopes = $transport->get();
        $count     = 0;

        foreach ($envelopes as $envelope) {
            if ($count >= $message->limit) {
                break;
            }

            $this->bus->dispatch($envelope);
            $transport->ack($envelope);
            $count++;
        }

        return Behavior::same();
    }
}
