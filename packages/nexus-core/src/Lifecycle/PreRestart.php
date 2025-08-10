<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

final readonly class PreRestart implements Signal
{
    public function __construct(
        public \Throwable $cause,
    ) {}
}
