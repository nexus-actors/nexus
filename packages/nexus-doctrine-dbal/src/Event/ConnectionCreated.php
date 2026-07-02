<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

/** @psalm-api */
final readonly class ConnectionCreated
{
    public function __construct(public string $poolName) {}
}
