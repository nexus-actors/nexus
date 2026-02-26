<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Runtime;

/** @psalm-api */
interface Cancellable
{
    public function cancel(): void;

    public function isCancelled(): bool;
}
