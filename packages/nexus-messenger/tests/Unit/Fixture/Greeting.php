<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('greeting')]
final readonly class Greeting
{
    public function __construct(public string $text)
    {
    }
}
