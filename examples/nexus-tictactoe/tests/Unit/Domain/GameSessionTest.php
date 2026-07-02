<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Domain;

use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameFullException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameOverException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\NotYourTurnException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\UnknownPlayerException;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * Rules of the aggregate — every invariant lives in {@see GameSession}, so
 * these run with no Doctrine, actor, or runtime in play.
 */
#[CoversClass(GameSession::class)]
final class GameSessionTest extends TestCase
{
    private const string TOKEN_X = '01JXAAAAAAAAAAAAAAAAAAAAAA';
    private const string TOKEN_O = '01JXBBBBBBBBBBBBBBBBBBBBBB';

    #[Test]
    public function fresh_game_waits_for_an_opponent_with_an_empty_board(): void
    {
        $game = new GameSession('01JXGAME');

        self::assertSame(GameStatus::WaitingForOpponent, $game->status());
        self::assertNull($game->playerX());
        self::assertSame([null, null, null, null, null, null, null, null, null], $game->toSnapshot()->board);
    }

    #[Test]
    public function second_join_starts_the_game_with_x_to_move(): void
    {
        $game = $this->started();

        self::assertSame(GameStatus::InProgress, $game->status());
        self::assertSame('Alice', $game->playerX()?->name);
        self::assertSame('Bob', $game->playerO()?->name);
        self::assertSame(PlayerMark::X, $game->toSnapshot()->nextTurn);
    }

    #[Test]
    public function a_third_distinct_player_cannot_join(): void
    {
        $game = $this->started();

        $this->expectException(GameFullException::class);
        $game->join('01JXCCCCCCCCCCCCCCCCCCCCCC', 'Carol');
    }

    #[Test]
    public function rejoining_under_the_same_token_only_refreshes_the_name(): void
    {
        $game = $this->started();

        $game->join(self::TOKEN_X, 'Alice II');

        self::assertSame('Alice II', $game->playerX()?->name);
        self::assertSame(GameStatus::InProgress, $game->status());
    }

    #[Test]
    public function moving_out_of_turn_is_rejected(): void
    {
        $game = $this->started();

        $this->expectException(NotYourTurnException::class);
        $game->makeMove(self::TOKEN_O, 0);
    }

    #[Test]
    public function an_unknown_token_is_rejected_without_leaking_the_token(): void
    {
        $game = $this->started();

        try {
            $game->makeMove('01JXSECRETSTOLENTOKENVALUE', 0);
            self::fail('expected UnknownPlayerException');
        } catch (UnknownPlayerException $e) {
            // Security: the seat token is a capability. It must never appear in
            // the exception message (which is logged and echoed to the client).
            self::assertFalse(
                str_contains($e->getMessage(), '01JXSECRETSTOLENTOKENVALUE'),
                'exception message leaked the seat token',
            );
        }
    }

    #[Test]
    public function completing_a_line_wins_the_game(): void
    {
        $game = $this->started();

        $game->makeMove(self::TOKEN_X, 0);
        $game->makeMove(self::TOKEN_O, 3);
        $game->makeMove(self::TOKEN_X, 1);
        $game->makeMove(self::TOKEN_O, 4);
        $game->makeMove(self::TOKEN_X, 2);

        self::assertSame(GameStatus::Won, $game->status());
        self::assertSame(PlayerMark::X, $game->toSnapshot()->winner);
    }

    #[Test]
    public function forfeiting_an_in_progress_game_awards_the_opponent(): void
    {
        $game = $this->started();

        $game->forfeit(self::TOKEN_X);

        self::assertSame(GameStatus::Won, $game->status());
        self::assertSame(PlayerMark::O, $game->toSnapshot()->winner);
    }

    #[Test]
    public function moving_before_the_game_starts_is_rejected(): void
    {
        $game = new GameSession('01JXGAME');
        $game->join(self::TOKEN_X, 'Alice');

        $this->expectException(GameOverException::class);
        $game->makeMove(self::TOKEN_X, 0);
    }

    private function started(): GameSession
    {
        $game = new GameSession('01JXGAME');
        $game->join(self::TOKEN_X, 'Alice');
        $game->join(self::TOKEN_O, 'Bob');

        return $game;
    }
}
