<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Represents a single failure reason for a RichSpecification evaluation.
 * `field` is a path (e.g., "address.zip"); `code` is a stable identifier
 * ("required"); `message` is a human-readable description.
 */
final readonly class Failure
{
    public function __construct(public string $field, public string $code, public string $message,) {}
}
