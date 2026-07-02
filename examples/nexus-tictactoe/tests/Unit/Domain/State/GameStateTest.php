<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Domain\State;

use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameDrawn;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameForfeited;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameWon;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\MoveMade;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\PlayerJoined;
use Monadial\Nexus\Example\TicTacToe\Domain\State\GameState;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The evolve half — folding events rebuilds state deterministically, which
 * is exactly what the persistence engine does on recovery.
 */
#[CoversClass(GameState::class)]
final class GameStateTest extends TestCase
{
    private const string TOKEN_X = '01JXAAAAAAAAAAAAAAAAAAAAAA';
    private const string TOKEN_O = '01JXBBBBBBBBBBBBBBBBBBBBBB';

    #[Test]
    public function an_empty_state_waits_with_a_blank_board(): void
    {
        $state = GameState::empty('01JXGAME');

        self::assertSame(GameStatus::WaitingForOpponent, $state->status);
        self::assertSame([null, null, null, null, null, null, null, null, null], $state->board);
        self::assertNull($state->markFor(self::TOKEN_X));
    }

    #[Test]
    public function the_second_join_starts_the_game_with_x_to_move(): void
    {
        $state = $this->started();

        self::assertSame(GameStatus::InProgress, $state->status);
        self::assertSame(PlayerMark::X, $state->nextTurn);
        self::assertSame(PlayerMark::X, $state->markFor(self::TOKEN_X));
        self::assertSame(PlayerMark::O, $state->markFor(self::TOKEN_O));
        self::assertSame('Alice', $state->toSnapshot()->playerX?->name);
    }

    #[Test]
    public function a_move_is_placed_and_the_turn_flips(): void
    {
        $state = $this->started()->apply(new MoveMade(PlayerMark::X, 4));

        self::assertSame([null, null, null, null, 'X', null, null, null, null], $state->board);
        self::assertSame(PlayerMark::O, $state->nextTurn);
    }

    #[Test]
    public function a_win_event_finishes_the_game(): void
    {
        $state = $this->started()->apply(new GameWon(PlayerMark::X));

        self::assertSame(GameStatus::Won, $state->status);
        self::assertSame(PlayerMark::X, $state->winner);
        self::assertNull($state->nextTurn);
    }

    #[Test]
    public function a_draw_event_finishes_with_no_winner(): void
    {
        $state = $this->started()->apply(new GameDrawn());

        self::assertSame(GameStatus::Draw, $state->status);
        self::assertNull($state->winner);
    }

    #[Test]
    public function a_forfeit_with_a_winner_is_a_win_and_without_is_abandoned(): void
    {
        $won = $this->started()->apply(new GameForfeited(PlayerMark::X, PlayerMark::O));
        $abandoned = $this->started()->apply(new GameForfeited(PlayerMark::X, null));

        self::assertSame(GameStatus::Won, $won->status);
        self::assertSame(PlayerMark::O, $won->winner);
        self::assertSame(GameStatus::Abandoned, $abandoned->status);
        self::assertNull($abandoned->winner);
    }

    private function started(): GameState
    {
        return GameState::empty('01JXGAME')
            ->apply(new PlayerJoined(self::TOKEN_X, 'Alice', PlayerMark::X))
            ->apply(new PlayerJoined(self::TOKEN_O, 'Bob', PlayerMark::O));
    }
}
