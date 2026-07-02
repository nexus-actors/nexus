<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

/**
 * The final cell was filled with no winner.
 */
final readonly class GameDrawn implements GameEvent {}
