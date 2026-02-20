<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Base exception for all serialization errors.
 */
abstract class SerializationException extends NexusException {}
