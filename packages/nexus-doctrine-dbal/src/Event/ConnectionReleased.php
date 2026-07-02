<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

use Monadial\Nexus\Runtime\Duration;

/** @psalm-api */
final readonly class ConnectionReleased
{
    public function __construct(public string $poolName, public Duration $heldFor) {}
}
