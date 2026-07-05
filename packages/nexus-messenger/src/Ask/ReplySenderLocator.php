<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Resolves the sender for a logical reply channel name.
 *
 * SSRF hardening: the X-Nexus-Reply-To wire header carries a LOGICAL name,
 * never a transport DSN. This locator is the ONLY resolution path from wire
 * values to senders — responders must never construct a transport from a
 * wire-supplied value. Unknown names resolve to null and must be rejected.
 *
 * Example:
 * ```php
 * $sender = $locator->senderFor($replyToStamp->channel);
 *
 * if ($sender === null) {
 *     // reject: unknown reply channel
 * }
 * ```
 *
 * @psalm-api
 */
interface ReplySenderLocator
{
    /**
     * Returns null for unknown channel names (callers must reject the reply).
     */
    public function senderFor(string $channel): ?SenderInterface;
}
