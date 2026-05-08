<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\DeadLetter;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadLetterEntry
{
    public function __construct(
        public Envelope $envelope,
        public Throwable $cause,
        public DateTimeImmutable $deadLetteredAt,
        public int $attemptsBeforeDeadLetter,
        public DeadLetterReason $reason,
    ) {}
}
