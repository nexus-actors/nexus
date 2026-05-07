<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Base for FRAMEWORK-INTERNAL faults — wiring errors the team made while
 * adopting the DDD package: a missing `applyXxx` method, ambiguous event
 * short-name resolution, replay machinery failure, no events recorded
 * where some were required, etc.
 *
 * Distinct from `DomainException`, which roots actual business rule
 * violations. Catching `NexusDddException` means "trap framework bugs";
 * catching `DomainException` means "react to a domain rule." Never the
 * same `catch` clause.
 */
abstract class NexusDddException extends RuntimeException {}
