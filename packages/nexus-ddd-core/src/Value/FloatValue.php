<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<float>
 */
abstract class FloatValue extends WrappedValue
{
    public function __construct(float $value)
    {
        parent::__construct($value);
    }
}
