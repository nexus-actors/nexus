<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<string>
 */
abstract class StringValue extends WrappedValue
{
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}
