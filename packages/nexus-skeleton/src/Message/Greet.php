<?php

declare(strict_types=1);

namespace App\Message;

/**
 * @psalm-api sample message of the skeleton template — sent by user code
 */
final readonly class Greet
{
    public function __construct(public string $name) {}
}
