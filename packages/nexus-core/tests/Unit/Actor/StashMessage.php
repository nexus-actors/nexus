<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

final readonly class StashMessage
{
    public function __construct(public string $value) {}
}
