<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-api
 *
 * Project-supplied validator. Returns `Violations` as a value — never
 * throws. The bus's ValidationMiddleware lifts non-empty `Violations` to
 * `ValidationFailedException`.
 */
interface Validator
{
    public function validate(object $message, ValidationContext $context): Violations;
}
