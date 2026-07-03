<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Provenance stamp: the path of the Nexus actor that produced an egress message.
 *
 * Attached by MessengerActorRef when a source path is configured, and
 * round-tripped by NexusMessengerSerializer as the X-Nexus-Source-Path header.
 *
 * @psalm-api
 */
final readonly class SourceActorPathStamp implements StampInterface
{
    public function __construct(public string $path) {}
}
