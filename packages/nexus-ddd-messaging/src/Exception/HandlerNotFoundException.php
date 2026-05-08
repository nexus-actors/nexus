<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Thrown by command/query handler locators when no handler is registered
 * for the dispatched message's concrete class.
 */
final class HandlerNotFoundException extends MessagingException {}
