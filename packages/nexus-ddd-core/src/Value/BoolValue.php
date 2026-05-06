<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<bool>
 */
abstract class BoolValue extends WrappedValue
{
    public function __construct(bool $value)
    {
        parent::__construct($value);
    }
}
