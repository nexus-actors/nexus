<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Value;

enum PlayerMark: string
{
    case X = 'X';
    case O = 'O';

    public function opponent(): self
    {
        return $this === self::X
            ? self::O
            : self::X;
    }
}
