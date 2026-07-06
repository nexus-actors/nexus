<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('test.ping')]
final readonly class Ping
{
    public function __construct(public string $text) {}
}
