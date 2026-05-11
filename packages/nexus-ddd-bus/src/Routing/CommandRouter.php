<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;

/**
 * @psalm-api
 *
 * Resolves a command class to its dispatching `CommandBus` by asking the
 * `RoutingStrategy` for the bus name and looking the impl up in the
 * `BusRegistry`. A `Composite` strategy always returns `Some` via its
 * default fallback, so `None` only occurs when a non-Composite strategy
 * is wired directly — in which case `BusNameNotRegisteredException` is
 * thrown with an empty resolution.
 */
final class CommandRouter
{
    public function __construct(private readonly BusRegistry $registry, private readonly RoutingStrategy $strategy,) {}

    /** @param class-string $messageClass */
    public function routeFor(string $messageClass): CommandBus
    {
        $resolution = $this->strategy->resolve($messageClass);

        if ($resolution->isNone()) {
            throw BusNameNotRegisteredException::for('<unresolved>', $this->registry->commandNames());
        }

        $busName = $resolution->getUnsafe()->busName;

        return $this->registry->command($busName)->getOrCall(
            fn(): never => throw BusNameNotRegisteredException::for($busName, $this->registry->commandNames()),
        );
    }
}
