<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

/**
 * Payload for `{"type": "error", "data": {"message": "..."}}`.
 */
final readonly class ErrorPayload
{
    public function __construct(public string $message) {}
}
