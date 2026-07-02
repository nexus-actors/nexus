<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * A player took a seat. Carries the assigned mark so the fold is a pure
 * function of the event — the second `PlayerJoined` is what starts the
 * game (both seats filled → X to move).
 */
final readonly class PlayerJoined implements GameEvent
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public PlayerMark $mark,
    ) {}
}
