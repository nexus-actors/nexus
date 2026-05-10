<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Root for bus-fabric faults — wiring errors and runtime contract
 * violations the bus layer detects. Distinct from `DomainException`
 * (business rule violations) and `MessagingException` (delivery-layer
 * faults).
 */
abstract class BusException extends NexusDddException {}
