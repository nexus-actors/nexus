<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Symfony\Component\Uid\Ulid;

/** @psalm-api */
final class UlidGenerator implements IdGenerator
{
    #[\Override]
    public function next(): Identifier
    {
        return new UlidValue((new Ulid())->toBase32());
    }
}
