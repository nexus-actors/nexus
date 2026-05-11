<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Bus\Attribute\Handler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class RetTypeFixtureCommandA implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class RetTypeFixtureCommandB implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class RetTypeFixtureCommandC implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * Good: every #[Handler] method returns void.
 */
final class GoodReturnTypeService
{
    #[Handler]
    public function place(RetTypeFixtureCommandA $command): void {}

    #[Handler]
    public function cancel(RetTypeFixtureCommandB $command): void {}
}

/**
 * Bad: #[Handler] methods that return non-void.
 */
final class BadReturnTypeService
{
    #[Handler]
    public function place(RetTypeFixtureCommandA $command): string
    {
        return 'placed';
    }

    #[Handler]
    public function cancel(RetTypeFixtureCommandB $command): int
    {
        return 0;
    }

    #[Handler]
    public function archive(RetTypeFixtureCommandC $command) {}
}
