<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at compile time when a route handler is given as a `'#name'` actor
 * shorthand string. Actor-backed routes are wired by registering the actor
 * with `HttpApp::actor()` and injecting it into the handler via a
 * `#[FromActor('name')]` parameter.
 */
final class ActorShorthandHandlerException extends NexusException
{
    public function __construct(string $handler)
    {
        $name = substr($handler, 1);

        parent::__construct(
            "Route handler '{$handler}' uses the unsupported actor shorthand. "
            . "Register the actor with ->actor('{$name}', ...) and inject it into the "
            . "handler with a #[FromActor('{$name}')] parameter instead.",
        );
    }
}
