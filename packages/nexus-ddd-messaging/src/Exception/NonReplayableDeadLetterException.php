<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a dead-lettered message cannot be replayed because the
 * information required to reconstruct a valid dispatch context is missing or
 * corrupted.
 */
final class NonReplayableDeadLetterException extends MessagingException implements TerminalFailure {}
