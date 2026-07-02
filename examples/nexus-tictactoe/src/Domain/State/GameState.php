<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\State;

use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameDrawn;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameEvent;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameForfeited;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameWon;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\MoveMade;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\PlayerJoined;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\Board;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\View\PlayerSeat;

/**
 * The event-sourced write-model state — a pure fold of {@see GameEvent}s.
 *
 * Nothing here talks to a database: the persistence engine rebuilds a
 * `GameState` on (re)start by replaying the event log through {@see apply()}
 * before the first command is handled. All decisions ({@see \Monadial\Nexus\Example\TicTacToe\Domain\GameRules})
 * read this immutable value; every transition returns a new instance.
 *
 * @psalm-immutable
 */
final readonly class GameState
{
    /**
     * @param list<?string> $board 9-cell row-major; each cell 'X', 'O', or null.
     */
    public function __construct(
        public string $gameId,
        public GameStatus $status,
        public ?string $playerXId,
        public ?string $playerXName,
        public ?string $playerOId,
        public ?string $playerOName,
        public array $board,
        public ?PlayerMark $nextTurn,
        public ?PlayerMark $winner,
    ) {}

    public static function empty(string $gameId): self
    {
        return new self(
            gameId: $gameId,
            status: GameStatus::WaitingForOpponent,
            playerXId: null,
            playerXName: null,
            playerOId: null,
            playerOName: null,
            board: Board::empty()->toArray(),
            nextTurn: null,
            winner: null,
        );
    }

    public function apply(GameEvent $event): self
    {
        return match (true) {
            $event instanceof PlayerJoined  => $this->seat($event),
            $event instanceof MoveMade      => $this->place($event),
            $event instanceof GameWon       => $this->finishWon($event->winner),
            $event instanceof GameDrawn      => $this->finishDrawn(),
            $event instanceof GameForfeited => $this->finishForfeited($event->winner),
        };
    }

    /**
     * The mark of a seated player, or `null` if this id holds no seat.
     * `$playerId` is a capability token; a miss is answered token-free by
     * the caller so the secret never reaches a log or the wire.
     */
    public function markFor(string $playerId): ?PlayerMark
    {
        return match ($playerId) {
            $this->playerXId => PlayerMark::X,
            $this->playerOId => PlayerMark::O,
            default          => null,
        };
    }

    public function board(): Board
    {
        return Board::fromCells($this->board);
    }

    public function toSnapshot(): GameSnapshot
    {
        return new GameSnapshot(
            gameId: $this->gameId,
            status: $this->status,
            playerX: $this->playerXId !== null && $this->playerXName !== null
                ? new PlayerSeat($this->playerXId, $this->playerXName)
                : null,
            playerO: $this->playerOId !== null && $this->playerOName !== null
                ? new PlayerSeat($this->playerOId, $this->playerOName)
                : null,
            board: $this->board,
            nextTurn: $this->nextTurn,
            winner: $this->winner,
        );
    }

    private function seat(PlayerJoined $event): self
    {
        $xId = $this->playerXId;
        $xName = $this->playerXName;
        $oId = $this->playerOId;
        $oName = $this->playerOName;

        if ($event->mark === PlayerMark::X) {
            $xId = $event->playerId;
            $xName = $event->playerName;
        } else {
            $oId = $event->playerId;
            $oName = $event->playerName;
        }

        $bothSeated = $xId !== null && $oId !== null;

        return new self(
            gameId: $this->gameId,
            status: $bothSeated
                ? GameStatus::InProgress
                : $this->status,
            playerXId: $xId,
            playerXName: $xName,
            playerOId: $oId,
            playerOName: $oName,
            board: $this->board,
            nextTurn: $bothSeated
                ? PlayerMark::X
                : $this->nextTurn,
            winner: $this->winner,
        );
    }

    private function place(MoveMade $event): self
    {
        $board = $this->board()->place($event->cell, $event->mark);

        return new self(
            gameId: $this->gameId,
            status: $this->status,
            playerXId: $this->playerXId,
            playerXName: $this->playerXName,
            playerOId: $this->playerOId,
            playerOName: $this->playerOName,
            board: $board->toArray(),
            nextTurn: $event->mark->opponent(),
            winner: $this->winner,
        );
    }

    private function finishWon(PlayerMark $winner): self
    {
        return $this->finish(GameStatus::Won, $winner);
    }

    private function finishDrawn(): self
    {
        return $this->finish(GameStatus::Draw, null);
    }

    private function finishForfeited(?PlayerMark $winner): self
    {
        $status = $winner === null
            ? GameStatus::Abandoned
            : GameStatus::Won;

        return $this->finish($status, $winner);
    }

    private function finish(GameStatus $status, ?PlayerMark $winner): self
    {
        return new self(
            gameId: $this->gameId,
            status: $status,
            playerXId: $this->playerXId,
            playerXName: $this->playerXName,
            playerOId: $this->playerOId,
            playerOName: $this->playerOName,
            board: $this->board,
            nextTurn: null,
            winner: $winner,
        );
    }
}
