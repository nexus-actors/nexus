<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Abstract base for auth-related exceptions. Sits under NexusException so
 * code that catches the project-wide base catches auth errors too.
 */
abstract class AuthException extends NexusException {}
