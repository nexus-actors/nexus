<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;

/**
 * The broadcast wire shape of a game. Deliberately DIFFERENT from the
 * domain {@see GameSnapshot}: seats are name-only ({@see SeatView}) so a
 * player's capability token never reaches other clients. This is the whole
 * reason the wire DTO exists separately from the read model — it is a
 * privacy boundary, not duplication for its own sake.
 */
final readonly class SnapshotPayload
{
    /**
     * @param list<?string> $board
     */
    public function __construct(
        public string $gameId,
        public string $status,
        public ?SeatView $playerX,
        public ?SeatView $playerO,
        public array $board,
        public ?string $nextTurn,
        public ?string $winner,
    ) {}

    public static function of(GameSnapshot $snapshot): self
    {
        return new self(
            gameId: $snapshot->gameId,
            status: $snapshot->status->value,
            playerX: $snapshot->playerX === null
                ? null
                : new SeatView($snapshot->playerX->name),
            playerO: $snapshot->playerO === null
                ? null
                : new SeatView($snapshot->playerO->name),
            board: $snapshot->board,
            nextTurn: $snapshot->nextTurn?->value,
            winner: $snapshot->winner?->value,
        );
    }
}
