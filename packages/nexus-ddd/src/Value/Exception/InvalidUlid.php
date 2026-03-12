<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value\Exception;

use DomainException;

final class InvalidUlid extends DomainException
{
    public function __construct(string $value)
    {
        parent::__construct(sprintf("'%s' is not a valid ULID", $value));
    }
}
