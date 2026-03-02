<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Stringable;

/**
 * Common contract for local and remote actor paths.
 *
 * @psalm-api
 */
interface ActorPathContract extends Stringable
{
    public function name(): string;

    public function parent(): ?self;

    public function equals(self $other): bool;

    public function isChildOf(self $parent): bool;

    public function isDescendantOf(self $ancestor): bool;

    public function depth(): int;
}
