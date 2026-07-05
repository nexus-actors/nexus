<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Override;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function method_exists;

/**
 * Reply channel backed by a concrete Messenger transport.
 *
 * close() semantics per lifecycle:
 * - Ephemeral/Persistent: no-op.
 * - DeleteOnShutdown: best-effort teardown. Symfony Messenger has no universal
 *   queue-delete API, so teardown only happens when the transport exposes a
 *   reset() method (ResetInterface convention); otherwise close() is a no-op.
 *   Note that reset() resets connection state — it does NOT delete the broker
 *   queue. Broker-side TTL/auto-delete on the queue is the authoritative
 *   cleanup for this lifecycle, and the backstop for crashed processes that
 *   never reach close().
 *
 * @internal created via TransportReplyChannelFactory
 */
final readonly class TransportReplyChannel implements ReplyChannel
{
    public function __construct(
        private string $name,
        private TransportInterface $transport,
        private ReplyQueueLifecycle $lifecycle,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function receiver(): ReceiverInterface
    {
        return $this->transport;
    }

    #[Override]
    public function close(): void
    {
        if ($this->lifecycle !== ReplyQueueLifecycle::DeleteOnShutdown) {
            return;
        }

        $transport = $this->transport;

        if (method_exists($transport, 'reset')) {
            $transport->reset();
        }
    }
}
