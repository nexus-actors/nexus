<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus;

/**
 * Wrap any domain event to publish it on the in-process context bus.
 * Lives in SharedKernel so all bounded-context Application actors can
 * depend on it without crossing the Platform layer boundary.
 */
final readonly class Publish
{
    public function __construct(public object $event) {}
}
