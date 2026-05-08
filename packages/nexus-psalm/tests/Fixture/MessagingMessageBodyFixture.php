<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Message\Query;

/** @psalm-immutable */
final readonly class GoodCommand implements Command
{
    public function __construct(public string $payload) {}
}

final class BadMutableCommand implements Command
{
    public string $payload = '';
}

final class BadNonFinalCommand implements Command
{
    public function __construct(public readonly string $payload) {}
}

/**
 * @psalm-immutable
 * @implements Query<string>
 */
final readonly class GoodQuery implements Query
{
    public function __construct(public string $criterion) {}
}

final class BadMutableQuery implements Query
{
    public string $criterion = '';
}
