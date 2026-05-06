<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Base for all Nexus DDD exceptions. Mirrors the `Monadial\Nexus\Core\Exception\NexusException`
 * convention from nexus-core but lives in a separate, decoupled namespace.
 */
abstract class NexusDddException extends RuntimeException {}
