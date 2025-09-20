<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Step\Messages;

final readonly class CountReply
{
    public function __construct(public int $count)
    {
    }
}
