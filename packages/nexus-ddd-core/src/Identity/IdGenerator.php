<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/** @psalm-api */
interface IdGenerator
{
    public function next(): Identifier;
}
