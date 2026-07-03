<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Override;
use Symfony\Component\Messenger\Envelope;

/**
 * Default router: exact message class → registered ActorRef.
 *
 * Example:
 * ```php
 * $router = new MapMessageRouter([OrderPlaced::class => $ordersRef]);
 * ```
 *
 * @psalm-api
 */
final readonly class MapMessageRouter implements MessageRouter
{
    /**
     * @param array<class-string, ActorRef<object>> $routes
     */
    public function __construct(private array $routes)
    {
    }

    #[Override]
    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        return $this->routes[$message::class] ?? null;
    }
}
