<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched after a reply message was successfully handed to the reply
 * sender by {@see \Monadial\Nexus\Messenger\Ask\MessengerReplyRef}.
 *
 * Emitted once per successful reply publish. The correlation ID ties this reply to the
 * original ask envelope received from the request transport.
 *
 * @psalm-api
 */
final readonly class ReplyPublished
{
    public function __construct(public object $message, public string $correlationId) {}
}
