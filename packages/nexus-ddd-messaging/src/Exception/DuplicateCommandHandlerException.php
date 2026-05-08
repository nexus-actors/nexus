<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when two or more handlers are registered for the same command type.
 */
final class DuplicateCommandHandlerException extends MessagingException implements TerminalFailure {}
