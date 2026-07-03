<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

/**
 * A domain "no" — a first-class value, not an exception. Invalid commands
 * are expected business outcomes; exceptions are reserved for defects.
 */
final readonly class Rejection
{
    public function __construct(public string $reason) {}
}
