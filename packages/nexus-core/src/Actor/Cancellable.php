<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

interface Cancellable
{
    public function cancel(): void;
    public function isCancelled(): bool;
}
