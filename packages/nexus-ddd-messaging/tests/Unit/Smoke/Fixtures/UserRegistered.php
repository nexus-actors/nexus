<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

final readonly class UserRegistered implements DomainEvent
{
    public function __construct(public string $userId) {}
}
