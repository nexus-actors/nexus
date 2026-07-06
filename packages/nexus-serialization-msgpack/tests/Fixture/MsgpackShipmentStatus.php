<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack\Tests\Fixture;

enum MsgpackShipmentStatus: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';
}
