<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameFullException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameOverException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\NotYourTurnException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\UnknownPlayerException;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\Board;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\View\PlayerSeat;

/**
 * Aggregate root for one game.
 *
 * Every rule (whose turn, cell occupied, game over) lives here. The
 * game actor calls one method; the aggregate mutates or throws. No rule
 * checks live in the actor layer.
 *
 * Concurrency: the `#[Version]` column turns any cross-worker overwrite
 * into a loud `OptimisticLockException` instead of a silent last-write-
 * wins race. Supervision restarts the actor on conflict; the reloaded
 * entity retries the command.
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

    #[Version]
    #[Column(type: 'integer')]
    private int $version = 1;

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
     * Seat a player. First joiner gets X, second gets O and the game moves
     * to `InProgress`. Re-join under the same id refreshes the display
     * name; returns silently for the "reconnect after dropped WS" case.
     */
    public function join(string $playerId, string $playerName): void
    {
        if ($this->playerXId === $playerId) {
            $this->playerXName = $playerName;

            return;
        }

        if ($this->playerOId === $playerId) {
            $this->playerOName = $playerName;

            return;
        }

        if ($this->playerXId === null) {
            $this->playerXId = $playerId;
            $this->playerXName = $playerName;

            return;
        }

        if ($this->playerOId === null) {
            $this->playerOId = $playerId;
            $this->playerOName = $playerName;
            $this->status = GameStatus::InProgress;
            $this->nextTurn = PlayerMark::X;

            return;
        }

        throw new GameFullException('both seats are taken');
    }

    /**
     * @param int<0, 8> $cellIndex
     */
    public function makeMove(string $playerId, int $cellIndex): void
    {
        if ($this->status !== GameStatus::InProgress) {
            throw new GameOverException("cannot move on a {$this->status->value} game");
        }

        $mark = $this->markFor($playerId);

        if ($mark !== $this->nextTurn) {
            throw new NotYourTurnException("it is {$this->nextTurn?->value}'s turn");
        }

        $board = Board::fromCells($this->board)->place($cellIndex, $mark);
        $this->board = $board->toArray();

        $winner = $board->winner();

        if ($winner !== null) {
            $this->status = GameStatus::Won;
            $this->winner = $winner;
            $this->nextTurn = null;
            $this->endedAt = new DateTimeImmutable();

            return;
        }

        if ($board->isFull()) {
            $this->status = GameStatus::Draw;
            $this->nextTurn = null;
            $this->endedAt = new DateTimeImmutable();

            return;
        }

        $this->nextTurn = $mark->opponent();
    }

    public function forfeit(string $playerId): void
    {
        if ($this->status->isTerminal()) {
            throw new GameOverException("game already {$this->status->value}");
        }

        $mark = $this->markFor($playerId);

        if ($this->status === GameStatus::InProgress) {
            $this->status = GameStatus::Won;
            $this->winner = $mark->opponent();
        } else {
            $this->status = GameStatus::Abandoned;
        }

        $this->nextTurn = null;
        $this->endedAt = new DateTimeImmutable();
    }

    private function markFor(string $playerId): PlayerMark
    {
        if ($this->playerXId === $playerId) {
            return PlayerMark::X;
        }

        if ($this->playerOId === $playerId) {
            return PlayerMark::O;
        }

        throw new UnknownPlayerException("player '{$playerId}' is not seated in this game");
    }
}
