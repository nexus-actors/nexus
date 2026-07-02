<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Domain\Value;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\CellOccupiedException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCellException;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\Board;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Board::class)]
final class BoardTest extends TestCase
{
    #[Test]
    public function an_empty_board_has_nine_null_cells(): void
    {
        self::assertSame([null, null, null, null, null, null, null, null, null], Board::empty()->toArray());
    }

    #[Test]
    public function placing_a_mark_is_immutable_and_recorded(): void
    {
        $empty = Board::empty();
        $placed = $empty->place(4, PlayerMark::X);

        self::assertSame([null, null, null, null, null, null, null, null, null], $empty->toArray());
        self::assertSame([null, null, null, null, 'X', null, null, null, null], $placed->toArray());
    }

    #[Test]
    public function an_occupied_cell_cannot_be_played_again(): void
    {
        $board = Board::empty()->place(0, PlayerMark::X);

        $this->expectException(CellOccupiedException::class);
        $board->place(0, PlayerMark::O);
    }

    #[Test]
    public function an_out_of_range_cell_is_rejected(): void
    {
        $this->expectException(InvalidCellException::class);
        Board::empty()->place(9, PlayerMark::X);
    }

    #[Test]
    public function a_diagonal_wins(): void
    {
        $board = Board::empty()
            ->place(0, PlayerMark::O)
            ->place(4, PlayerMark::O)
            ->place(8, PlayerMark::O);

        self::assertSame(PlayerMark::O, $board->winner());
    }

    #[Test]
    public function an_incomplete_board_has_no_winner(): void
    {
        $board = Board::empty()->place(0, PlayerMark::X)->place(1, PlayerMark::O);

        self::assertNull($board->winner());
        self::assertFalse($board->isFull());
    }

    #[Test]
    public function fromCells_rejects_a_wrong_length_board(): void
    {
        $this->expectException(InvalidCellException::class);
        Board::fromCells(['X', 'O']);
    }
}
