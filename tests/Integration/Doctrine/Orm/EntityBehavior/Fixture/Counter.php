<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'counters')]
final class Counter
{
    #[Id]
    #[Column]
    public string $id;

    #[Column]
    public int $value = 0;

    public function __construct(string $id, int $value = 0)
    {
        $this->id = $id;
        $this->value = $value;
    }

    /** Mutator returns true so command handlers can express add+persist in a single match arm. */
    public function tryAdd(int $delta): bool
    {
        $this->value += $delta;

        return true;
    }
}
