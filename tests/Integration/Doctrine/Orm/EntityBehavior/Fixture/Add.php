<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture;

final readonly class Add
{
    public function __construct(public int $delta) {}
}
