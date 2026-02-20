<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence\Messages;

final readonly class ValueState
{
    public function __construct(public string $value = '')
    {
    }
}
