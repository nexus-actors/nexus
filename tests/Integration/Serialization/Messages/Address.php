<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

final readonly class Address
{
    public function __construct(public string $street, public string $city, public string $zip, public string $country,) {}
}
