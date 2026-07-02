<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\View;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * Read-model view of a game — plain data. The aggregate owns the factory
 * (`GameSession::toSnapshot()`); the view never reaches back into it.
 */
final readonly class GameSnapshot
{
    /**
     * @param list<?string> $board 9-cell row-major; each cell is 'X', 'O', or null.
     */
    public function __construct(
        public string $gameId,
        public GameStatus $status,
        public ?PlayerSeat $playerX,
        public ?PlayerSeat $playerO,
        public array $board,
        public ?PlayerMark $nextTurn,
        public ?PlayerMark $winner,
    ) {}
}
