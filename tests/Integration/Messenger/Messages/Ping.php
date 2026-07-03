<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('ping')]
final readonly class Ping
{
    public function __construct(public string $id) {}
}
