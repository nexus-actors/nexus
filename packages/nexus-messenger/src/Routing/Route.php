<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * One message-class → actor binding with a typed construction boundary:
 * {@see Route::to()} verifies at analysis time that the target ref actually
 * handles the routed message class. Inside a router the entries live in a
 * heterogeneous map (PHP cannot express per-key generics), but every entry
 * was type-checked when it was built.
 *
 * Example:
 * ```php
 * $router = new MapMessageRouter(Route::to(OrderPlaced::class, $ordersRef));
 * ```
 *
 * @psalm-api
 *
 * @template-covariant T of object
 */
final readonly class Route
{
    /**
     * @param class-string<T> $messageClass
     * @param ActorRef<T> $target
     */
    private function __construct(public string $messageClass, public ActorRef $target) {}

    /**
     * @template M of object
     * @param class-string<M> $messageClass
     * @param ActorRef<M> $target
     * @return self<M>
     */
    public static function to(string $messageClass, ActorRef $target): self
    {
        return new self($messageClass, $target);
    }
}
