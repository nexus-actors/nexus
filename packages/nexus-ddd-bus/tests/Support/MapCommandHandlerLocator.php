<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

use function sprintf;

/**
 * Test fixture: maps command class-strings to their handler instances.
 * Throws `HandlerNotFoundException` for unregistered classes — matching
 * the contract documented on `CommandHandlerLocator`.
 *
 * @psalm-api
 */
final class MapCommandHandlerLocator implements CommandHandlerLocator
{
    /**
     * @param array<class-string<Command>, CommandHandler> $handlers
     */
    public function __construct(private array $handlers = []) {}

    /**
     * @param class-string<Command> $commandClass
     */
    public function register(string $commandClass, CommandHandler $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    #[Override]
    public function locate(Command $command): CommandHandler
    {
        $class = $command::class;

        if (!isset($this->handlers[$class])) {
            throw new HandlerNotFoundException(sprintf('No handler registered for `%s`.', $class));
        }

        return $this->handlers[$class];
    }
}
