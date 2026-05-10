<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Override;
use Throwable;

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

    /** @return Either<Throwable, Accepted> */
    #[Override]
    public function tryDispatch(Command $command): Either
    {
        $this->recorded[] = $command;

        return Either::right(new Accepted());
    }

    /** @return list<Command> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
