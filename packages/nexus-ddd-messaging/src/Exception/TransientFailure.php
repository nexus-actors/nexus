<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Marker for exceptions the bus SHOULD retry. Implementing this interface
 * signals that the failure is temporary and a subsequent attempt may succeed.
 */
interface TransientFailure {}
