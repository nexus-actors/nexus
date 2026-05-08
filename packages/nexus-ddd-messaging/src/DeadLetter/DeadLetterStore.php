<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\DeadLetter;

use Monadial\Nexus\Ddd\Messaging\Exception\NonReplayableDeadLetterException;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;

/**
 * @psalm-api
 *
 * Stores envelopes that the bus could not deliver. Reasons split into
 * delivery failures (replayable once root cause is fixed) and invalid
 * messages (never replayable — `replay()` throws
 * NonReplayableDeadLetterException).
 */
interface DeadLetterStore
{
    public function record(DeadLetterEntry $entry): void;

    /**
     * @throws NonReplayableDeadLetterException when the entry's reason is
     *         non-replayable (Invalid_*).
     */
    public function replay(MessageId $messageId): void;

    /** @return iterable<int, DeadLetterEntry> */
    public function pending(): iterable;
}
