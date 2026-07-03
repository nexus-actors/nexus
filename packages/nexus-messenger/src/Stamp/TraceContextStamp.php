<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the W3C Trace-Context (or any propagation carrier) across the broker
 * boundary. Attached by the producer before the message is serialised and
 * restored by {@see \Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer}
 * via the {@code X-Nexus-Trace-Context} header so the consumer can open a
 * child span that continues the originating trace.
 *
 * @psalm-api
 */
final readonly class TraceContextStamp implements StampInterface
{
    /** @param array<string, string> $carrier */
    public function __construct(public array $carrier) {}
}
