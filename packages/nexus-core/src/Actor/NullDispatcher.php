<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @psalm-api
 *
 * No-op event dispatcher for when no external dispatcher is provided.
 */
final class NullDispatcher implements EventDispatcherInterface
{
    #[Override]
    public function dispatch(object $event): object
    {
        return $event;
    }
}
