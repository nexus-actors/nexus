<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use Symfony\Component\Uid\Uuid;

/** @psalm-api */
final class UuidGenerator implements IdGenerator
{
    #[\Override]
    public function next(): Identifier
    {
        return new UuidValue((string) Uuid::v7());
    }
}
