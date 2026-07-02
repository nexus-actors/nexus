<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Command;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCommandException;

use function strlen;
use function trim;

final readonly class MakeMove implements GameCommand
{
    public string $playerId;

    public function __construct(string $playerId, public int $cellIndex)
    {
        $playerId = trim($playerId);

        if ($playerId === '' || strlen($playerId) > 64) {
            throw new InvalidCommandException('playerId must be 1-64 chars');
        }

        if ($cellIndex < 0 || $cellIndex > 8) {
            throw new InvalidCommandException('cellIndex must be 0..8');
        }

        $this->playerId = $playerId;
    }
}
