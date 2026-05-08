<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Resolution;

use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/**
 * @psalm-api
 *
 * @throws HandlerNotFoundException when no handler is registered for the
 *         command's concrete class.
 */
interface CommandHandlerLocator
{
    public function locate(Command $command): CommandHandler;
}
