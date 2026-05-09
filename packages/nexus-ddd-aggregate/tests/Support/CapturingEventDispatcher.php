<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Support;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Test-double PSR-14 dispatcher. Appends each dispatched event to
 * `$captured` (a public mutable list) and returns the event unchanged.
 * Tests assert against `$captured` after exercising the SUT.
 */
final class CapturingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $captured = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->captured[] = $event;

        return $event;
    }
}
