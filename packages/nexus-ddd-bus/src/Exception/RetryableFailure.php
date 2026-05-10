<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Marker for retryable failures. The `IdempotencyReserveMiddleware`
 * RELEASES the reservation on these (allowing future redelivery).
 * Co-equal with `Messaging\Exception\TerminalFailure`.
 */
interface RetryableFailure {}
