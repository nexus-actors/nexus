<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Value;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\CellOccupiedException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCellException;

use function count;
use function in_array;

/**
 * Immutable 3x3 board. Cells are 0..8 row-major:
 *   0 | 1 | 2
 *   3 | 4 | 5
 *   6 | 7 | 8
 *
 * @psalm-immutable
 */
final readonly class Board
{
    private const array LINES = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6],
    ];

    /** @param list<?PlayerMark> $cells */
    private function __construct(private array $cells) {}

    public static function empty(): self
    {
        return new self([null, null, null, null, null, null, null, null, null]);
    }

    /**
     * @param list<mixed> $cells Anything can arrive from a corrupted JSON column;
     *        every cell is validated before construction.
     */
    public static function fromCells(array $cells): self
    {
        if (count($cells) !== 9) {
            throw new InvalidCellException('board must have exactly 9 cells, got ' . count($cells));
        }

        $marks = [];

        foreach ($cells as $c) {
            if ($c === null) {
                $marks[] = null;

                continue;
            }

            if (!$c instanceof PlayerMark) {
                $marks[] = PlayerMark::tryFrom((string) $c)
                    ?? throw new InvalidCellException('invalid cell value');

                continue;
            }

            $marks[] = $c;
        }

        return new self($marks);
    }

    public function place(int $cellIndex, PlayerMark $mark): self
    {
        if ($cellIndex < 0 || $cellIndex > 8) {
            throw new InvalidCellException("cell index out of range: {$cellIndex}");
        }

        if ($this->cells[$cellIndex] !== null) {
            throw new CellOccupiedException("cell {$cellIndex} is already occupied");
        }

        $next = $this->cells;
        $next[$cellIndex] = $mark;

        return new self($next);
    }

    public function winner(): ?PlayerMark
    {
        foreach (self::LINES as [$a, $b, $c]) {
            $ma = $this->cells[$a];

            if ($ma !== null && $ma === $this->cells[$b] && $ma === $this->cells[$c]) {
                return $ma;
            }
        }

        return null;
    }

    public function isFull(): bool
    {
        return !in_array(null, $this->cells, true);
    }

    /**
     * Serialise as a list of `?string` for Doctrine JSON storage.
     *
     * @return list<?string>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->cells as $c) {
            $out[] = $c?->value;
        }

        return $out;
    }
}
