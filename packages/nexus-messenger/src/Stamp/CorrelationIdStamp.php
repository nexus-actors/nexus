<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Correlation ID stamp: a unique identifier that correlates request/response pairs.
 *
 * Attached by MessengerActorRef or manually when a correlation ID is needed, and
 * round-tripped by NexusMessengerSerializer as the X-Nexus-Correlation-Id header.
 *
 * @psalm-api
 */
final readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(public string $id) {}
}
