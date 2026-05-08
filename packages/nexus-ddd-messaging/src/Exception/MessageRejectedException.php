<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a message is explicitly rejected by a handler or policy.
 * Rejection is a deliberate decision; retrying would produce the same outcome.
 */
final class MessageRejectedException extends MessagingException implements TerminalFailure {}
