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
    #[\NoDiscard('next() consumes a fresh identifier — discarding it wastes the generation')]
    public function next(): Identifier;
}
