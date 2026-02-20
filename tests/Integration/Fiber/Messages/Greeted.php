<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Messages;

/** @psalm-api */
final readonly class Greeted
{
    public function __construct(public string $greeting)
    {
    }
}
