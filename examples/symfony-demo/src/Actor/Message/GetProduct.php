<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class GetProduct
{
    public function __construct(public string $id) {}
}
