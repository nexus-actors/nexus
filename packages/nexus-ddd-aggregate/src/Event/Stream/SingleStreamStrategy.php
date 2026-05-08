<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Stream;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-api
 *
 * Default stream strategy: every aggregate's events live in a single
 * logical stream named `ddd_events`. Logical streams via filter on
 * (aggregate_type, aggregate_id) are always available. Simplest
 * operationally; the right default for most apps.
 */
final readonly class SingleStreamStrategy implements StreamStrategy
{
    #[Override]
    public function streamFor(string $aggregateClass, Identifier $id): StreamName
    {
        return new StreamName('ddd_events');
    }
}
