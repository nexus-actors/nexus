<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Tests\Unit\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('console.ping')]
final readonly class PingMessage
{
    public function __construct(public string $id)
    {
    }
}
