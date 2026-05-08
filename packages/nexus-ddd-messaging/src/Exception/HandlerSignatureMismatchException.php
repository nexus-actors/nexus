<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a registered handler's method signature does not match the
 * expected contract for the message type it claims to handle.
 */
final class HandlerSignatureMismatchException extends MessagingException implements TerminalFailure {}
