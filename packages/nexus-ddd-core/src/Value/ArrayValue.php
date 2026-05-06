<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<array>
 */
abstract class ArrayValue extends WrappedValue
{
    /**
     * @param array<array-key, mixed> $value
     */
    public function __construct(array $value)
    {
        parent::__construct($value);
    }
}
