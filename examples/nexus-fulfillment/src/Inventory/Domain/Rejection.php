<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Domain;

/**
 * A domain "no" — a first-class value, not an exception. Invalid commands
 * are expected business outcomes; exceptions are reserved for defects.
 *
 * Deliberately duplicated from Orders\Domain\Rejection: cross-context
 * Domain imports are forbidden by the architecture ruleset. A shared version
 * can graduate to SharedKernel once a third context needs it.
 */
final readonly class Rejection
{
    public function __construct(public string $reason) {}
}
