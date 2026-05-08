<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a message is appended to a staging buffer that has already been
 * committed or rolled back. Marked as TerminalFailure because the staging is
 * permanently closed — retrying with the same envelope would loop forever.
 */
final class StagingClosedException extends MessagingException implements TerminalFailure {}
