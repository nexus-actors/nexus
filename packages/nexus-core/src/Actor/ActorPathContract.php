<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Stringable;

/**
 * Common contract for local and remote actor paths.
 *
 * @psalm-api
 */
interface ActorPathContract extends Stringable
{
    public function name(): string;

    /** @return Option<self> */
    public function parent(): Option;

    public function equals(self $other): bool;

    public function isChildOf(self $parent): bool;

    public function isDescendantOf(self $ancestor): bool;

    public function depth(): int;
}
