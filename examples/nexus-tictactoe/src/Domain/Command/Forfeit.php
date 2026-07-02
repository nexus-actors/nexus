<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Command;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCommandException;

use function strlen;
use function trim;

final readonly class Forfeit implements GameCommand
{
    public string $playerId;

    public function __construct(string $playerId)
    {
        $playerId = trim($playerId);

        if ($playerId === '' || strlen($playerId) > 64) {
            throw new InvalidCommandException('playerId must be 1-64 chars');
        }

        $this->playerId = $playerId;
    }
}
