<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\ReadModel;

use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;

/**
 * The write side projects each new authoritative state here. Kept as an
 * interface so the event-sourced game actor depends on "somewhere to write
 * the lobby view", not on Doctrine — the actor code is unchanged whether the
 * projection lands in Postgres, an in-memory map (tests), or a search index.
 */
interface GameReadModel
{
    public function apply(GameSnapshot $snapshot): void;
}
