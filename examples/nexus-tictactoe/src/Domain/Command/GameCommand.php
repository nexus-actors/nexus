<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Command;

/**
 * Marker for the closed set of commands the aggregate accepts. PHP has no
 * `sealed` keyword — the game actor's match(true) has a `default` arm that
 * throws, so any new implementer surfaces immediately.
 */
interface GameCommand {}
