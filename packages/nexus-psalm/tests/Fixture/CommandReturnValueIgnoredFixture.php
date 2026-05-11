<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class RetValFixtureCommand implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * Good: call dispatchCommand() as a statement, never as an expression
 * whose return value is captured.
 */
final class GoodReturnValueClient
{
    public function __construct(private readonly CommandBus $bus) {}

    public function send(): void
    {
        $this->bus->dispatchCommand(new RetValFixtureCommand('ok'));
    }
}

/**
 * Bad: assigning the void return value to a variable is dead code.
 */
final class BadReturnValueClient
{
    public function __construct(private readonly CommandBus $bus) {}

    /** @psalm-suppress AssignmentToVoid, UnusedVariable */
    public function send(): void
    {
        $result = $this->bus->dispatchCommand(new RetValFixtureCommand('bad'));
    }
}
