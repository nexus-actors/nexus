<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * Thrown when a Messenger-backed ref is asked to do something the broker
 * boundary cannot support in v1 (currently: ask() request/reply).
 *
 * @psalm-api
 */
final class UnsupportedOperationException extends NexusException
{
}
