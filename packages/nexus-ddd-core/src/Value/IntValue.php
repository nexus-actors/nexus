<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<int>
 */
abstract readonly class IntValue extends WrappedValue
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }
}
