<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence\Messages;

final readonly class ItemAdded
{
    public function __construct(public string $item)
    {
    }
}
