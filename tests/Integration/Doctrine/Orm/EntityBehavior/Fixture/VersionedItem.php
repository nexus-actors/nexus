<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;

#[Entity]
#[Table(name: 'versioned_items')]
final class VersionedItem
{
    #[Id]
    #[Column]
    public string $id;

    #[Column]
    public int $count = 0;

    #[Version]
    #[Column(type: 'integer')]
    public int $version = 1;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
