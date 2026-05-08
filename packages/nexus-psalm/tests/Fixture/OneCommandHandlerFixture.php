<?php

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Functions.DisallowEmptyFunction

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class DupCommandX implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class DupCommandY implements Command
{
    public function __construct(public string $payload) {}
}

final class FirstHandlerForX implements CommandHandler
{
    public function __invoke(DupCommandX $command): void {}
}

final class SecondHandlerForX implements CommandHandler
{
    public function __invoke(DupCommandX $command): void {}
}

final class OnlyHandlerForY implements CommandHandler
{
    public function __invoke(DupCommandY $command): void {}
}
