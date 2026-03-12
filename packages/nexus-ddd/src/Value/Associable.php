<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value;

/**
 * @psalm-api
 *
 * Value objects implementing this interface can serve as process manager correlation keys.
 * The process manager router calls associationValue() to extract the routing key string.
 */
interface Associable
{
    public function associationValue(): string;
}
