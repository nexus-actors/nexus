<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a message is appended to a staging buffer that has already been
 * committed or rolled back. Does not carry a failure-marker because this is an
 * operator-level lifecycle issue rather than a categorically transient or
 * terminal messaging fault.
 */
final class StagingClosedException extends MessagingException {}
