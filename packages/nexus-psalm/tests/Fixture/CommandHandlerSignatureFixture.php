<?php

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Functions.DisallowEmptyFunction

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class CmdHandlerCommandA implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class CmdHandlerCommandB implements Command
{
    public function __construct(public string $payload) {}
}

final class GoodCommandHandler implements CommandHandler
{
    public function __invoke(CmdHandlerCommandA $command): void {}
}

final class GoodCommandHandlerWithContext implements CommandHandler
{
    public function __invoke(CmdHandlerCommandB $command, MessageContext $ctx): void {}
}

final class BadCommandHandlerNoInvoke implements CommandHandler {}

final class BadCommandHandlerWrongReturn implements CommandHandler
{
    public function __invoke(CmdHandlerCommandA $command): string
    {
        return 'oops';
    }
}

final class BadCommandHandlerNoFirstParamType implements CommandHandler
{
    public function __invoke(mixed $command): void {}
}
