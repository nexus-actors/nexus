<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Wraps a user-level actor message for transit across a TCP cluster link.
 * `body` is the raw msgpack bytes produced by the cluster's message serializer.
 * `trace` carries OpenTelemetry propagation headers (e.g. traceparent/tracestate).
 */
#[MessageType('cluster.message')]
final readonly class MessagePayload
{
    /**
     * @param array<string, string> $trace OpenTelemetry propagation headers.
     */
    public function __construct(
        public string $targetPath,
        public string $messageType,
        public string $body,
        public ?string $correlationId,
        public ?string $replyPath,
        public array $trace,
    ) {}
}
