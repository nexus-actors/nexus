<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Event;

/**
 * Marker for the closed set of facts a game can record. Events are the
 * source of truth: the {@see \Monadial\Nexus\Example\TicTacToe\Domain\State\GameState}
 * is rebuilt by folding these in order, and the read model is a projection
 * of the same stream. Every implementer is a `final readonly` value with
 * only serialisable, promoted properties (PHP-native serialisation).
 */
interface GameEvent {}
