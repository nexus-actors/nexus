<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class StockLevel
{
    /** @param array<string, int> $levels */
    public function __construct(public array $levels) {}
}
