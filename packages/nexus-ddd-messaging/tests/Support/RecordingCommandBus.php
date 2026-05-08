<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Override;

/**
 * @psalm-api
 *
 * Test double for CommandBus. Records every dispatched command so tests
 * can assert what was sent without a real bus implementation.
 */
final class RecordingCommandBus implements CommandBus
{
    /** @var list<Command> */
    private array $recorded = [];

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $this->recorded[] = $command;
    }

    /** @return list<Command> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
