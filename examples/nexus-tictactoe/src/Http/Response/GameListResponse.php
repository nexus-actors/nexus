<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Response;

/**
 * @psalm-api
 */
final readonly class GameListResponse
{
    /**
     * @param list<GameSummary> $games
     */
    public function __construct(public array $games) {}
}
