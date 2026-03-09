<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen;

interface Resettable
{
    public function reset(): void;
}
