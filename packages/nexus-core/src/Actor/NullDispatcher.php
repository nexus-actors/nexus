<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * No-op event dispatcher for when no external dispatcher is provided.
 */
final class NullDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
