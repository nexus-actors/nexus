<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

/**
 * @psalm-api
 *
 * A single cluster TCP frame. The payload is opaque msgpack bytes — the codec
 * only moves raw bytes; higher layers (de)serialize the payload.
 */
final readonly class Frame
{
    public function __construct(public FrameType $type, public string $payload) {}
}
