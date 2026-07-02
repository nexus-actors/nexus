<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * A mark was placed on a cell. The fold places it and flips the turn; a
 * terminal outcome (win/draw) is recorded as its own event so the log
 * reads as a narrative rather than requiring the reader to re-derive it.
 */
final readonly class MoveMade implements GameEvent
{
    public function __construct(public PlayerMark $mark, public int $cell) {}
}
