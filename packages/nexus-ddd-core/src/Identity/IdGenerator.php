<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/**
 * @psalm-api
 *
 * @template T of Identifier
 */
interface IdGenerator
{
    /** @return T */
    public function next(): Identifier;
}
