<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Value;

enum GameStatus: string
{
    case WaitingForOpponent = 'waiting';
    case InProgress = 'in_progress';
    case Won = 'won';
    case Draw = 'draw';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Won, self::Draw, self::Abandoned => true,
            self::WaitingForOpponent, self::InProgress => false,
        };
    }
}
