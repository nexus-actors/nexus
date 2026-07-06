<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Tests\Unit\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('console.pong')]
final readonly class PongMessage
{
    public function __construct(public string $body)
    {
    }
}
