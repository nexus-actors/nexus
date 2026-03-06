<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Listener;

use Monadial\Nexus\Runtime\Async\Future;
use Symfony\Component\HttpKernel\Event\ViewEvent;

/**
 * Resolves Future<Response> returns from controllers.
 *
 * Controllers may return either a plain Response (synchronous) or a
 * Future<Response> (async fan-out via actor ask()). This listener handles
 * the latter case: it calls await() on the Future, which suspends the
 * current Swoole onRequest coroutine (non-blocking) until the Future resolves.
 *
 * @psalm-api
 */
final class FutureResponseListener
{
    public function __invoke(ViewEvent $event): void
    {
        $result = $event->getControllerResult();

        if (!$result instanceof Future) {
            return;
        }

        $event->setResponse($result->await());
    }
}
