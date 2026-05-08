<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Marker for exceptions that MUST NOT be retried. Implementing this interface
 * signals that the failure is permanent and retrying would be meaningless or
 * harmful.
 */
interface TerminalFailure {}
