<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Root for messaging-layer faults. Distinct from `NexusDddException`
 * (core framework wiring) and `DomainException` (business rule
 * violations) — messaging failures are runtime delivery faults,
 * neither of those.
 */
abstract class MessagingException extends RuntimeException {}
