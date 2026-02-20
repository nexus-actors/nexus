<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Base for ALL checked Nexus exceptions.
 * Psalm tracks this as a checked exception class.
 * Every subclass must be declared in @throws.
 */
abstract class NexusException extends RuntimeException {}
