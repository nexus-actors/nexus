<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Hook;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @psalm-api
 *
 * No-op PSR-14 dispatcher. Returned as the default constructor argument
 * on event-store and snapshot-store impls so apps that don't wire a
 * dispatcher pay zero ceremony. The dispatch contract is honored —
 * the event is returned unchanged — so callers that depend on the
 * return value continue to work.
 */
final readonly class NullEventDispatcher implements EventDispatcherInterface
{
    #[Override]
    public function dispatch(object $event): object
    {
        return $event;
    }
}
