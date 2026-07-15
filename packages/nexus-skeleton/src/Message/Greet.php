<?php

declare(strict_types=1);

namespace App\Message;

final readonly class Greet
{
    public function __construct(public string $name) {}
}
