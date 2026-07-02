<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Command;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCommandException;

use function strlen;
use function trim;

final readonly class JoinGame implements GameCommand
{
    public string $playerId;
    public string $playerName;

    public function __construct(string $playerId, string $playerName)
    {
        $playerId = trim($playerId);
        $playerName = trim($playerName);

        if ($playerId === '' || strlen($playerId) > 64) {
            throw new InvalidCommandException('playerId must be 1-64 chars');
        }

        if ($playerName === '' || strlen($playerName) > 40) {
            throw new InvalidCommandException('playerName must be 1-40 chars');
        }

        $this->playerId = $playerId;
        $this->playerName = $playerName;
    }
}
