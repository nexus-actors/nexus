<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Base for boot-time misconfiguration faults — duplicate routing, missing
 * validator/decider registrations, profile/bus mismatch. Implements
 * `BusInvariantException` so `tryDispatch()` propagates these (boot
 * errors are not domain failures and must not be lifted to
 * `Either::left`).
 */
abstract class BusBootException extends BusException implements BusInvariantException {}
