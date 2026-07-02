<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Response;

/**
 * @psalm-api
 */
final readonly class CreateGameResponse
{
    public function __construct(public string $gameId, public string $wsUrl) {}
}
