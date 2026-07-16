<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

#[CoversClass(ActorCell::class)]
final class ActorCellReceiveTimeoutTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;

    #[Test]
    public function receiveTimeoutSignalFiresWhenIdle(): void
    {
        $signalsReceived = [];

        /** @var Behavior<object> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$signalsReceived): Behavior {
                $ctx->setReceiveTimeout(Duration::millis(100));

                /** @var Behavior<object> */
                return Behavior::receive(
                    static fn(ActorContext $c, object $msg): Behavior => Behavior::same(),
                )->onSignal(
                    static function (ActorContext $c, Signal $signal) use (&$signalsReceived): Behavior {
                        $signalsReceived[] = $signal;

                        return Behavior::same();
                    },
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send one user message — this arms (or re-arms) the 100ms timer
        $cell->processMessage($this->envelope(new stdClass()));

        // Advance past 100ms — timer fires, ReceiveTimeout signal delivered
        $this->runtime->advanceTime(Duration::millis(101));

        $receiveTimeouts = array_filter(
            $signalsReceived,
            static fn(Signal $s): bool => $s instanceof ReceiveTimeout,
        );

        self::assertCount(1, $receiveTimeouts, 'Expected exactly 1 ReceiveTimeout signal');
    }

    #[Test]
    public function userMessageResetsTimer(): void
    {
        $signalsReceived = [];

        /** @var Behavior<object> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$signalsReceived): Behavior {
                $ctx->setReceiveTimeout(Duration::millis(100));

                /** @var Behavior<object> */
                return Behavior::receive(
                    static fn(ActorContext $c, object $msg): Behavior => Behavior::same(),
                )->onSignal(
                    static function (ActorContext $c, Signal $signal) use (&$signalsReceived): Behavior {
                        $signalsReceived[] = $signal;

                        return Behavior::same();
                    },
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // First message arms the timer
        $cell->processMessage($this->envelope(new stdClass()));

        // Advance 80ms — not yet fired
        $this->runtime->advanceTime(Duration::millis(80));

        // Second message resets the timer (cancels old, arms new 100ms)
        $cell->processMessage($this->envelope(new stdClass()));

        // Advance 50ms more — only 50ms since the last message, so no timeout yet
        $this->runtime->advanceTime(Duration::millis(50));

        $receiveTimeoutsFirst = array_filter(
            $signalsReceived,
            static fn(Signal $s): bool => $s instanceof ReceiveTimeout,
        );

        self::assertCount(0, $receiveTimeoutsFirst, 'Timer should not have fired 50ms after reset');

        // Advance another 60ms — now 110ms since the last message, timer fires
        $this->runtime->advanceTime(Duration::millis(60));

        $receiveTimeoutsFinal = array_filter(
            $signalsReceived,
            static fn(Signal $s): bool => $s instanceof ReceiveTimeout,
        );

        self::assertCount(1, $receiveTimeoutsFinal, 'Expected exactly 1 ReceiveTimeout after idle period');
    }

    #[Test]
    public function setReceiveTimeoutNullCancelsArmedTimer(): void
    {
        $signalsReceived = [];

        /** @var Behavior<object> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$signalsReceived): Behavior {
                $ctx->setReceiveTimeout(Duration::millis(100));

                /** @var Behavior<object> */
                return Behavior::receive(
                    static function (ActorContext $c, object $msg): Behavior {
                        // First message: disable the timeout
                        $c->setReceiveTimeout(null);

                        return Behavior::same();
                    },
                )->onSignal(
                    static function (ActorContext $c, Signal $signal) use (&$signalsReceived): Behavior {
                        $signalsReceived[] = $signal;

                        return Behavior::same();
                    },
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Message arms, then immediately disables the timeout
        $cell->processMessage($this->envelope(new stdClass()));

        // Advance well past the timeout window
        $this->runtime->advanceTime(Duration::millis(500));

        $receiveTimeouts = array_filter(
            $signalsReceived,
            static fn(Signal $s): bool => $s instanceof ReceiveTimeout,
        );

        self::assertCount(0, $receiveTimeouts, 'Cancelled timeout must not fire');
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
    }

    // ---- Helpers ----

    /**
     * @template T of object
     * @param Behavior<T> $behavior
     * @return ActorCell<T>
     */
    private function createCell(Behavior $behavior): ActorCell
    {
        return new ActorCell(
            $behavior,
            ActorPath::fromString('/user/timeout-test'),
            TestMailbox::unbounded(),
            $this->runtime,
            null,
            SupervisionStrategy::oneForOne(),
            $this->runtime->clock(),
            new NullLogger(),
            $this->deadLetters,
            new NoopObservability(),
        );
    }

    private function envelope(object $message): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::root(),
            ActorPath::fromString('/user/timeout-test'),
        );
    }
}
