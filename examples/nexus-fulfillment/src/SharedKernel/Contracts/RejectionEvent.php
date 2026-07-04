<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts;

/**
 * Marker interface for domain rejection events. Rejection events are persisted
 * business facts — an invariant was violated but the system records it as an
 * auditable outcome rather than silently dropping it.
 *
 * Form chosen: `reason(): string` method rather than the PHP 8.4 abstract
 * property hook `public string $reason { get; }`, to avoid potential
 * Psalm/PHPCS edge cases with readonly-promoted properties satisfying
 * abstract property hooks across tooling versions.
 */
interface RejectionEvent
{
    public function reason(): string;
}
