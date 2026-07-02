<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Event;

/** @psalm-api */
final readonly class EntityManagerEvicted
{
    public function __construct(public string $poolName, public string $reason) {}
}
