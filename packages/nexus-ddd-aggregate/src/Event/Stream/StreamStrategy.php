<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Stream;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Determines which logical stream a given (aggregateClass, identifier) belongs to.
 * Public surface is logical-only — physical table-name resolution is an
 * internal concern of the storage adapter (DBAL, Doctrine).
 *
 * Earlier drafts of this interface exposed `tableFor(string): string`, which
 * leaked physical layout into application code. Removed in v6 §9.3.
 */
interface StreamStrategy
{
    /** @param class-string $aggregateClass */
    public function streamFor(string $aggregateClass, Identifier $id): StreamName;
}
