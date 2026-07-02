<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\Board;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\View\PlayerSeat;

/**
 * Lobby READ MODEL for one game — a denormalised projection, not the write
 * model.
 *
 * The rules and the source of truth live in the event-sourced write side
 * ({@see \Monadial\Nexus\Example\TicTacToe\Domain\GameRules} decides,
 * {@see \Monadial\Nexus\Example\TicTacToe\Domain\State\GameState} folds the
 * event log). The game actor projects each new state into this `games` row
 * via {@see applySnapshot()} so the REST lobby (`GET /api/games`,
 * `GET /api/games/{id}`) can answer "which games are live?" and "show me the
 * board" with a plain indexed query — the one thing an event log alone can't
 * do cheaply.
 *
 * Because a game is single-writer (one actor per id), the projection needs
 * no optimistic-lock column: the actor is the only writer, so last-write is
 * the only write.
 */
#[Entity]
#[Table(name: 'games')]
#[Index(columns: ['status', 'created_at'])]
final class GameSession
{
    #[Id]
    #[Column]
    private string $id;

    #[Column(enumType: GameStatus::class)]
    private GameStatus $status;

    #[Column(name: 'player_x_id', nullable: true)]
    private ?string $playerXId = null;

    #[Column(name: 'player_x_name', nullable: true)]
    private ?string $playerXName = null;

    #[Column(name: 'player_o_id', nullable: true)]
    private ?string $playerOId = null;

    #[Column(name: 'player_o_name', nullable: true)]
    private ?string $playerOName = null;

    /** @var list<?string> */
    #[Column(type: 'json')]
    private array $board;

    #[Column(nullable: true, enumType: PlayerMark::class)]
    private ?PlayerMark $nextTurn = null;

    #[Column(nullable: true, enumType: PlayerMark::class)]
    private ?PlayerMark $winner = null;

    #[Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[Column(name: 'ended_at', nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->status = GameStatus::WaitingForOpponent;
        $this->board = Board::empty()->toArray();
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): GameStatus
    {
        return $this->status;
    }

    public function playerX(): ?PlayerSeat
    {
        return $this->playerXId !== null && $this->playerXName !== null
            ? new PlayerSeat($this->playerXId, $this->playerXName)
            : null;
    }

    public function playerO(): ?PlayerSeat
    {
        return $this->playerOId !== null && $this->playerOName !== null
            ? new PlayerSeat($this->playerOId, $this->playerOName)
            : null;
    }

    public function toSnapshot(): GameSnapshot
    {
        return new GameSnapshot(
            gameId: $this->id,
            status: $this->status,
            playerX: $this->playerX(),
            playerO: $this->playerO(),
            board: $this->board,
            nextTurn: $this->nextTurn,
            winner: $this->winner,
        );
    }

    /**
     * Overwrite this projection with the latest authoritative snapshot from
     * the event-sourced write side. Called by the game actor after each
     * batch of events is persisted and folded.
     */
    public function applySnapshot(GameSnapshot $snapshot): void
    {
        $this->status = $snapshot->status;
        $this->playerXId = $snapshot->playerX?->id;
        $this->playerXName = $snapshot->playerX?->name;
        $this->playerOId = $snapshot->playerO?->id;
        $this->playerOName = $snapshot->playerO?->name;
        $this->board = $snapshot->board;
        $this->nextTurn = $snapshot->nextTurn;
        $this->winner = $snapshot->winner;

        if ($this->endedAt === null && $snapshot->status->isTerminal()) {
            $this->endedAt = new DateTimeImmutable();
        }
    }
}
