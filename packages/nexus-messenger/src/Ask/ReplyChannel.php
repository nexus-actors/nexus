<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * A per-asker reply channel backed by a Messenger transport.
 *
 * The logical name() is what gets advertised on the wire in the
 * X-Nexus-Reply-To header; the receiver() is polled locally for replies.
 *
 * Example:
 * ```php
 * $channel = $factory->create();
 * $envelope = $envelope->with(new ReplyToStamp($channel->name()));
 * // ... poll $channel->receiver() for replies, then:
 * $channel->close();
 * ```
 *
 * @psalm-api
 */
interface ReplyChannel
{
    /**
     * The logical channel name advertised in X-Nexus-Reply-To.
     */
    public function name(): string;

    /**
     * The transport receiver to poll for inbound replies.
     */
    public function receiver(): ReceiverInterface;

    /**
     * Release the channel. Semantics depend on the queue lifecycle:
     * no-op for Ephemeral/Persistent, best-effort teardown for DeleteOnShutdown.
     */
    public function close(): void;
}
