<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Profile;

/**
 * @psalm-api
 *
 * Deployment profile selector. Determines which bus implementations are
 * available at runtime and how the OCC retry middleware behaves.
 */
enum Profile: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Actor = 'actor';

    public function isSync(): bool
    {
        return $this === self::Sync;
    }

    public function allowsAsyncBus(): bool
    {
        return $this === self::Async || $this === self::Actor;
    }

    public function allowsActorBus(): bool
    {
        return $this === self::Actor;
    }
}
