<?php

declare(strict_types=1);

namespace Monadial\Nexus\App;

/**
 * @psalm-api
 *
 * Name -> ActorHandler class-string map, populated by the skeleton's AsActorPass from every
 * #[AsActor]-tagged service, then read by the Kernel to spawn each actor at boot.
 */
final class ActorRegistry
{
    /** @var array<string, class-string> */
    private array $actors = [];

    /**
     * @param class-string $class
     */
    public function register(string $name, string $class): void
    {
        $this->actors[$name] = $class;
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->actors;
    }
}
