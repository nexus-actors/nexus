<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

/**
 * @psalm-api
 *
 * Lifecycle mode for an injected actor. Set once at registration.
 */
enum ActorMode
{
    /** One actor for the entire worker pool, addressed via hash ring. */
    case PoolSingleton;

    /** One instance per worker thread. */
    case WorkerLocal;

    /** Spawned per HTTP request, stopped at request end. */
    case PerRequest;
}
