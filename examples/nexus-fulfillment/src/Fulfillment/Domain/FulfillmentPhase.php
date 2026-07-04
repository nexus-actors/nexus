<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain;

/**
 * Lifecycle phase of a fulfillment process.
 *
 * Transitions: Reserving → Completed | Compensated (terminal).
 */
enum FulfillmentPhase: string
{
    case Compensated = 'compensated';
    case Completed = 'completed';
    case Reserving = 'reserving';
}
