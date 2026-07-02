<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use RuntimeException;

/**
 * Raised when a WebSocket upgrade targets a `/ws/games/{id}` whose `{id}`
 * is not a well-formed ULID — a hand-crafted request trying to spawn a
 * game at an attacker-chosen key. The channel actor rejects the connection
 * rather than mint one.
 */
final class InvalidGameIdException extends RuntimeException {}
