<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('shipment.created')]
final readonly class ShipmentCreated
{
    public function __construct(public string $shipmentId, public Address $address) {}
}
