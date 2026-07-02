<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * A move completed a line. Recorded right after the {@see MoveMade} that
 * caused it.
 */
final readonly class GameWon implements GameEvent
{
    public function __construct(public PlayerMark $winner) {}
}
