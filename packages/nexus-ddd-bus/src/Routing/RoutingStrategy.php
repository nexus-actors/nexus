<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Resolves a message class to a target bus name. Implementations live in
 * a `Composite` chain walked in registration order; the first `Some(...)`
 * wins. `None` means "this strategy doesn't know — try the next."
 */
interface RoutingStrategy
{
    /**
     * @param class-string $messageClass
     * @return Option<RoutingResolution>
     */
    public function resolve(string $messageClass): Option;
}
