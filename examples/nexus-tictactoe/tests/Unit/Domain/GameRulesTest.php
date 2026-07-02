<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Domain;

use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameForfeited;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameWon;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\MoveMade;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\PlayerJoined;
use Monadial\Nexus\Example\TicTacToe\Domain\GameRules;
use Monadial\Nexus\Example\TicTacToe\Domain\State\GameState;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function str_contains;

/**
 * The decide half of the aggregate — pure, no persistence, no actor. These
 * are the same rules the old state-stored aggregate enforced, now expressed
 * as a function from (state, command) to events-or-rejection.
 */
#[CoversClass(GameRules::class)]
#[CoversClass(GameState::class)]
final class GameRulesTest extends TestCase
{
    private const string TOKEN_X = '01JXAAAAAAAAAAAAAAAAAAAAAA';
    private const string TOKEN_O = '01JXBBBBBBBBBBBBBBBBBBBBBB';

    #[Test]
    public function first_join_seats_player_x(): void
    {
        $decision = GameRules::join(GameState::empty('01JXGAME'), new JoinGame(self::TOKEN_X, 'Alice'));

        self::assertFalse($decision->isRejected());
        self::assertCount(1, $decision->events);
        self::assertInstanceOf(PlayerJoined::class, $decision->events[0]);
        self::assertSame(PlayerMark::X, $decision->events[0]->mark);
    }

    #[Test]
    public function a_third_distinct_player_is_rejected(): void
    {
        $decision = GameRules::join($this->started(), new JoinGame('01JXCCCCCCCCCCCCCCCCCCCCCC', 'Carol'));

        self::assertTrue($decision->isRejected());
        self::assertSame('both seats are taken', $decision->rejection);
    }

    #[Test]
    public function reconnecting_under_a_held_seat_produces_no_event(): void
    {
        $decision = GameRules::join($this->started(), new JoinGame(self::TOKEN_X, 'Alice'));

        self::assertFalse($decision->isRejected());
        self::assertSame([], $decision->events);
    }

    #[Test]
    public function moving_out_of_turn_is_rejected(): void
    {
        $decision = GameRules::move($this->started(), new MakeMove(self::TOKEN_O, 0));

        self::assertTrue($decision->isRejected());
        self::assertSame("it is X's turn", $decision->rejection);
    }

    #[Test]
    public function an_unknown_token_is_rejected_without_leaking_the_token(): void
    {
        $decision = GameRules::move($this->started(), new MakeMove('01JXSECRETSTOLENTOKENVALUE', 0));

        self::assertTrue($decision->isRejected());
        self::assertSame('player is not seated in this game', $decision->rejection);
        self::assertFalse(str_contains((string) $decision->rejection, '01JXSECRETSTOLENTOKENVALUE'));
    }

    #[Test]
    public function moving_before_the_game_starts_is_rejected(): void
    {
        $waiting = GameState::empty('01JXGAME')->apply(new PlayerJoined(self::TOKEN_X, 'Alice', PlayerMark::X));

        $decision = GameRules::move($waiting, new MakeMove(self::TOKEN_X, 0));

        self::assertTrue($decision->isRejected());
        self::assertSame('cannot move on a waiting game', $decision->rejection);
    }

    #[Test]
    public function a_winning_move_emits_move_then_win(): void
    {
        $state = $this->started()
            ->apply(new MoveMade(PlayerMark::X, 0))
            ->apply(new MoveMade(PlayerMark::O, 3))
            ->apply(new MoveMade(PlayerMark::X, 1))
            ->apply(new MoveMade(PlayerMark::O, 4));

        $decision = GameRules::move($state, new MakeMove(self::TOKEN_X, 2));

        self::assertFalse($decision->isRejected());
        self::assertSame(2, count($decision->events));
        self::assertInstanceOf(MoveMade::class, $decision->events[0]);
        self::assertInstanceOf(GameWon::class, $decision->events[1]);
        self::assertSame(PlayerMark::X, $decision->events[1]->winner);
    }

    #[Test]
    public function forfeiting_an_in_progress_game_awards_the_opponent(): void
    {
        $decision = GameRules::forfeit($this->started(), new Forfeit(self::TOKEN_X));

        self::assertFalse($decision->isRejected());
        self::assertInstanceOf(GameForfeited::class, $decision->events[0]);
        self::assertSame(PlayerMark::X, $decision->events[0]->by);
        self::assertSame(PlayerMark::O, $decision->events[0]->winner);
    }

    private function started(): GameState
    {
        return GameState::empty('01JXGAME')
            ->apply(new PlayerJoined(self::TOKEN_X, 'Alice', PlayerMark::X))
            ->apply(new PlayerJoined(self::TOKEN_O, 'Bob', PlayerMark::O));
    }
}
