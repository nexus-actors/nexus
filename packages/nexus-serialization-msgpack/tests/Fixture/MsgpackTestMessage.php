<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack\Tests\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('msgpack.test')]
final readonly class MsgpackTestMessage
{
    public function __construct(public string $text, public int $number) {}
}
