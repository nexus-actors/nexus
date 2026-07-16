<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

/**
 * A seated player as seen by OTHER clients: name only. The internal seat
 * id is a capability token and is never broadcast — only its owner learns
 * it, once, via {@see WelcomePayload}.
 *
 * @psalm-api
 */
final readonly class SeatView
{
    public function __construct(public string $name) {}
}
