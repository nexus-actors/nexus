<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

use function sprintf;

/**
 * @psalm-api
 *
 * Lifted by ValidationMiddleware when an application `Validator` returns a
 * non-empty `Violations`. A domain fact (the message broke a business rule)
 * AND a terminal failure (no amount of retrying will make an invalid
 * message valid).
 */
final class ValidationFailedException extends DomainException implements TerminalFailure
{
    private function __construct(string $message, private readonly Violations $violations)
    {
        parent::__construct($message);
    }

    public static function with(Violations $violations): self
    {
        return new self(
            sprintf('Validation failed with %d violation(s).', $violations->count()),
            $violations,
        );
    }

    public function violations(): Violations
    {
        return $this->violations;
    }
}
