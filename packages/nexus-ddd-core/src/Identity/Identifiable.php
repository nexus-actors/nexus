<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/** @psalm-api */
interface Identifiable
{
    public function id(): Identifier;
}
