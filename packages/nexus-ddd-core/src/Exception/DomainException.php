<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Base for all DOMAIN exceptions — rule violations the business itself
 * recognizes (invalid identifier format, optimistic lock / concurrency
 * violation, invariant breach, etc.). Catch this when you want to react
 * to a domain rule, not when you want to react to framework wiring.
 *
 * Distinct from `NexusDddException`, which roots framework-internal
 * faults (missing applyXxx method, ambiguous event short-name, replay
 * machinery failure). A handler that wants "ignore the domain rule" must
 * never accidentally swallow framework wiring errors — hence the split.
 */
abstract class DomainException extends RuntimeException {}
