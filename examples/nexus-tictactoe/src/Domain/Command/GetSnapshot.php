<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Command;

/**
 * Read-only query — returns the current snapshot without mutating state.
 */
final readonly class GetSnapshot implements GameCommand {}
