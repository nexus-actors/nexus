<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Response;

/**
 * @psalm-api
 */
final readonly class GameSummary
{
    public function __construct(
        public string $gameId,
        public string $status,
        public ?string $playerX,
        public ?string $playerO,
    ) {}
}
