<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCommandException;

/**
 * `{"type":"move","cell":4}` — no player id. The channel actor stamps the
 * mover from the authenticated connection.
 *
 * @psalm-api
 */
final readonly class MoveIntent implements ClientIntent
{
    public function __construct(public int $cell)
    {
        if ($cell < 0 || $cell > 8) {
            throw new InvalidCommandException('cell must be 0..8');
        }
    }
}
