<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal\NamespaceA;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

final readonly class Collided implements DomainEvent
{
    public function __construct(public string $payload) {}
}
