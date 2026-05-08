<?php

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Functions.DisallowEmptyFunction

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Messaging\Handler\QueryHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Query;

/**
 * @psalm-immutable
 * @implements Query<string>
 */
final readonly class QueryHandlerQueryA implements Query
{
    public function __construct(public string $criterion) {}
}

final class GoodQueryHandler implements QueryHandler
{
    public function __invoke(QueryHandlerQueryA $query): string
    {
        return 'result';
    }
}

final class BadQueryHandlerNoInvoke implements QueryHandler {}

final class BadQueryHandlerVoidReturn implements QueryHandler
{
    public function __invoke(QueryHandlerQueryA $query): void {}
}
