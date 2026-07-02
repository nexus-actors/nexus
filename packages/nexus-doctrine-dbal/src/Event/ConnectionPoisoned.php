<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

/** @psalm-api */
final readonly class ConnectionPoisoned
{
    public function __construct(public string $poolName, public string $reason) {}
}
