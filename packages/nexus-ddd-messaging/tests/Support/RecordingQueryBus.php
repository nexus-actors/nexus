<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Message\Query;

/**
 * @psalm-api
 *
 * Test double for QueryBus. Pre-load canned responses via respondWith();
 * dispatchQuery returns the canned value or throws HandlerNotFoundException
 * when none is registered. Records every dispatch for assertion.
 */
final class RecordingQueryBus implements QueryBus
{
    /** @var array<class-string, mixed> */
    private array $responses = [];

    /** @var list<Query> */
    private array $recorded = [];

    /** @param class-string<Query> $queryClass */
    public function respondWith(string $queryClass, mixed $response): void
    {
        $this->responses[$queryClass] = $response;
    }

    #[\Override]
    public function dispatchQuery(Query $query): mixed
    {
        $this->recorded[] = $query;

        if (!array_key_exists($query::class, $this->responses)) {
            throw new HandlerNotFoundException(
                sprintf('No canned response registered for %s', $query::class),
            );
        }

        return $this->responses[$query::class];
    }

    /** @return list<Query> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
