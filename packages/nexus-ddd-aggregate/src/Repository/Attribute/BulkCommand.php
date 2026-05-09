<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Repository\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a `iterable<T>`-returning method on an `AggregateRepository`
 * subclass as an intentional command-side bulk loader (e.g.
 * `OrderRepository::inBatch(BatchId): iterable<Order>`).
 *
 * Per v6 §9.1, repositories serve only command-side loading. The default
 * shape is `find()` / `save()`. Bulk reads are tolerated only when the
 * caller is a command handler that needs to mutate every item — for read
 * queries, use `QueryBus` against a projection table instead.
 *
 * The Psalm rule `AggregateRepositoryReadOnlyBulk` flags any
 * iterable-returning method that lacks this attribute. The
 * `$justification` field is required documentation: it forces the author
 * to record *why* the bulk method exists at the call-site.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class BulkCommand
{
    public function __construct(public string $justification) {}
}
