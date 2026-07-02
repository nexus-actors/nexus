<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * A player forfeited. `winner` is the opponent when the game was in
 * progress, or `null` when a waiting game is abandoned before it began.
 */
final readonly class GameForfeited implements GameEvent
{
    public function __construct(public PlayerMark $by, public ?PlayerMark $winner) {}
}
