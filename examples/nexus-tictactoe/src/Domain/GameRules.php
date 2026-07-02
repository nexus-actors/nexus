<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain;

use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameDrawn;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameForfeited;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameWon;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\MoveMade;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\PlayerJoined;
use Monadial\Nexus\Example\TicTacToe\Domain\State\GameState;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;

/**
 * The decision half of the aggregate: given the current {@see GameState} and
 * a mutating command, return the events to persist or a token-free
 * rejection. Pure and side-effect free — no persistence, no actor, no clock
 * — which is exactly what makes the rules unit-testable in isolation and
 * keeps event replay deterministic (the fold lives in {@see GameState}).
 *
 * `$playerId` throughout is the server-issued capability token; rejection
 * messages name the rule, never the token.
 *
 * @psalm-immutable
 */
final readonly class GameRules
{
    public static function join(GameState $state, JoinGame $command): GameDecision
    {
        // Reconnect under an existing seat: no new fact, the actor just
        // re-welcomes the connection with the current snapshot.
        if ($state->markFor($command->playerId) !== null) {
            return GameDecision::accept();
        }

        if ($state->playerXId === null) {
            return GameDecision::accept(
                new PlayerJoined($command->playerId, $command->playerName, PlayerMark::X),
            );
        }

        if ($state->playerOId === null) {
            return GameDecision::accept(
                new PlayerJoined($command->playerId, $command->playerName, PlayerMark::O),
            );
        }

        return GameDecision::reject('both seats are taken');
    }

    public static function move(GameState $state, MakeMove $command): GameDecision
    {
        $mark = $state->markFor($command->playerId);

        if ($mark === null) {
            return GameDecision::reject('player is not seated in this game');
        }

        if ($state->status !== GameStatus::InProgress) {
            return GameDecision::reject("cannot move on a {$state->status->value} game");
        }

        if ($mark !== $state->nextTurn) {
            return GameDecision::reject("it is {$state->nextTurn?->value}'s turn");
        }

        if ($state->board[$command->cellIndex] !== null) {
            return GameDecision::reject("cell {$command->cellIndex} is already occupied");
        }

        $board = $state->board()->place($command->cellIndex, $mark);
        $events = [new MoveMade($mark, $command->cellIndex)];

        $winner = $board->winner();

        if ($winner !== null) {
            $events[] = new GameWon($winner);
        } elseif ($board->isFull()) {
            $events[] = new GameDrawn();
        }

        return GameDecision::accept(...$events);
    }

    public static function forfeit(GameState $state, Forfeit $command): GameDecision
    {
        $mark = $state->markFor($command->playerId);

        if ($mark === null) {
            return GameDecision::reject('player is not seated in this game');
        }

        if ($state->status->isTerminal()) {
            return GameDecision::reject("game already {$state->status->value}");
        }

        $winner = $state->status === GameStatus::InProgress
            ? $mark->opponent()
            : null;

        return GameDecision::accept(new GameForfeited($mark, $winner));
    }
}
