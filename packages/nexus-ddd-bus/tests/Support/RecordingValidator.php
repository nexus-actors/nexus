<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Override;

/**
 * Test fixture: a `Validator` that records each invocation as a
 * `[message, context]` tuple and returns the preconfigured `Violations`.
 * Defaults to empty so the happy path is opt-out.
 */
final class RecordingValidator implements Validator
{
    /** @var list<array{context: ValidationContext, message: object}> */
    public array $calls = [];

    public function __construct(private Violations $violations) {}

    public static function returningEmpty(): self
    {
        return new self(Violations::empty());
    }

    public static function returning(Violations $violations): self
    {
        return new self($violations);
    }

    #[Override]
    public function validate(object $message, ValidationContext $context): Violations
    {
        $this->calls[] = ['context' => $context, 'message' => $message];

        return $this->violations;
    }
}
