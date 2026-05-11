<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNotAvailableInProfileException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;

use function array_keys;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable map of bus name → bus impl, built once by `BusBuilder` after
 * all handlers are registered. Holds parallel maps for command, query,
 * and event buses.
 *
 * `validateRoutes()` checks every resolved route against the registered
 * bus names and the active `Profile`. For P0 only `SyncCommandBus`
 * exists, so `profileAllows()` is trivially true; it expands in P3+ when
 * async/actor bus impls ship.
 */
final readonly class BusRegistry
{
    /**
     * @param array<string, CommandBus> $commandBuses
     * @param array<string, QueryBus> $queryBuses
     * @param array<string, EventBus> $eventBuses
     */
    public function __construct(
        public Profile $profile,
        public array $commandBuses,
        public array $queryBuses,
        public array $eventBuses,
    ) {}

    /** @return Option<CommandBus> */
    public function command(string $name): Option
    {
        return Option::fromNullable($this->commandBuses[$name] ?? null);
    }

    /** @return list<string> */
    public function commandNames(): array
    {
        return array_keys($this->commandBuses);
    }

    /** @return Option<QueryBus> */
    public function query(string $name): Option
    {
        return Option::fromNullable($this->queryBuses[$name] ?? null);
    }

    /** @return list<string> */
    public function queryNames(): array
    {
        return array_keys($this->queryBuses);
    }

    /** @return Option<EventBus> */
    public function event(string $name): Option
    {
        return Option::fromNullable($this->eventBuses[$name] ?? null);
    }

    /** @return list<string> */
    public function eventNames(): array
    {
        return array_keys($this->eventBuses);
    }

    /**
     * @param iterable<class-string, RoutingResolution> $resolutions
     * @throws BusNameNotRegisteredException
     * @throws BusNotAvailableInProfileException
     */
    public function validateRoutes(iterable $resolutions): void
    {
        foreach ($resolutions as $resolution) {
            if (!isset($this->commandBuses[$resolution->busName])) {
                throw BusNameNotRegisteredException::for(
                    $resolution->busName,
                    $this->commandNames(),
                );
            }

            $bus = $this->commandBuses[$resolution->busName];

            if (!$this->profileAllows($bus)) {
                throw BusNotAvailableInProfileException::for($resolution->busName, $this->profile);
            }
        }
    }

    /**
     * P0 stub: only `SyncCommandBus` exists, so every registered impl is
     * compatible with every profile. Expand when async/actor buses ship.
     *
     * @psalm-suppress UnusedParam — kept for forward-compat signature.
     */
    private function profileAllows(CommandBus $bus): bool
    {
        return true;
    }
}
