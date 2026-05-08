<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\DeadLetter;

use Monadial\Nexus\Ddd\Messaging\DeadLetter\DeadLetterReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeadLetterReason::class)]
final class DeadLetterReasonTest extends TestCase
{
    #[Test]
    public function replayableReasonsCoverDeliveryFailures(): void
    {
        $replayable = array_filter(
            DeadLetterReason::cases(),
            static fn(DeadLetterReason $r) => $r->isReplayable(),
        );

        self::assertSame(
            [
                DeadLetterReason::Expired,
                DeadLetterReason::TerminalFailure,
                DeadLetterReason::Timeout,
                DeadLetterReason::TransientFailureExhausted,
            ],
            array_values($replayable),
        );
    }

    #[Test]
    public function nonReplayableReasonsCoverInvalidMessages(): void
    {
        $nonReplayable = array_filter(
            DeadLetterReason::cases(),
            static fn(DeadLetterReason $r) => !$r->isReplayable(),
        );

        self::assertSame(
            [
                DeadLetterReason::Invalid_DeserializationFailure,
                DeadLetterReason::Invalid_HandlerSignatureMismatch,
                DeadLetterReason::Invalid_NoHandlerRegistered,
                DeadLetterReason::Invalid_SchemaValidationFailure,
            ],
            array_values($nonReplayable),
        );
    }
}
