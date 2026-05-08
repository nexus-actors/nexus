<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown when a message cannot be dispatched. Does not carry a failure-marker
 * because the retry semantics depend on the underlying cause — callers should
 * inspect the previous exception to determine whether the failure is transient.
 */
final class MessageDispatchException extends MessagingException {}
