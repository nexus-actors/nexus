<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Override;
use Symfony\Component\Messenger\Envelope;

/**
 * Default router: exact message class → registered ActorRef.
 *
 * Routes are built through the typed {@see Route::to()} boundary, which
 * checks that each target ref handles its routed message class. Later routes
 * for the same message class override earlier ones.
 *
 * Example:
 * ```php
 * $router = new MapMessageRouter(Route::to(OrderPlaced::class, $ordersRef));
 * ```
 *
 * @psalm-api
 */
final readonly class MapMessageRouter implements MessageRouter
{
    /** @var array<class-string, Route<object>> */
    private array $routes;

    /**
     * @param Route<object> ...$routes
     */
    public function __construct(Route ...$routes)
    {
        $map = [];

        foreach ($routes as $route) {
            $map[$route->messageClass] = $route;
        }

        $this->routes = $map;
    }

    #[Override]
    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        return ($this->routes[$message::class] ?? null)?->target;
    }
}
