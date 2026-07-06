<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack\Tests\Fixture;

use DateTimeImmutable;
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('msgpack.rich')]
final readonly class MsgpackRichMessage
{
    public function __construct(
        public string $text,
        public DateTimeImmutable $occurredAt,
        public MsgpackShipmentStatus $status,
    ) {}
}
