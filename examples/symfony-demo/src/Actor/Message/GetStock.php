<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class GetStock
{
    /** @param string[] $productIds */
    public function __construct(public array $productIds) {}
}
