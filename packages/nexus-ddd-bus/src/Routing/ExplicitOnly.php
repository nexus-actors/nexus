<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Highest-priority strategy in the standard Composite chain. Operators
 * pin a specific message class to a specific bus name; resolution
 * returns `Some` only for exact class-string matches and `None`
 * otherwise.
 */
final class ExplicitOnly implements RoutingStrategy
{
    /** @var array<class-string, string> */
    private array $routes = [];

    /** @param class-string $messageClass */
    #[NoDiscard('explicit() returns this — assign or chain')]
    public function explicit(string $messageClass, string $busName): self
    {
        $this->routes[$messageClass] = $busName;

        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return Option::fromNullable($this->routes[$messageClass] ?? null)
            ->map(static fn(string $busName): RoutingResolution => new RoutingResolution($busName, self::class));
    }
}
