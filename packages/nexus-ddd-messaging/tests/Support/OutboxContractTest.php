<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-api
 *
 * Contract test for Outbox implementations. Extend this class and
 * implement createOutbox() to verify a new outbox impl satisfies the
 * contract without duplicating test logic.
 */
abstract class OutboxContractTest extends TestCase
{
    protected RecordingEnvelopedCommandBus $cmdBus;

    protected RecordingEnvelopedEventBus $evtBus;

    abstract protected function createOutbox(
        RecordingEnvelopedCommandBus $cmdBus,
        RecordingEnvelopedEventBus $evtBus,
    ): Outbox;

    #[Test]
    public function flushDispatchesInFifoOrder(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $cmd1 = new class () implements Command {};
        $cmd2 = new class () implements Command {};
        $outbox->appendCommand($cmd1, Option::none());
        $outbox->appendCommand($cmd2, Option::none());
        $outbox->flush();

        $envelopes = $this->cmdBus->recordedEnvelopes();
        self::assertCount(2, $envelopes);
        self::assertSame($cmd1, $envelopes[0]->message);
        self::assertSame($cmd2, $envelopes[1]->message);
    }

    #[Test]
    public function flushDispatchesCommandsBeforeEvents(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $evt = new class () implements DomainEvent {};
        $cmd = new class () implements Command {};
        $outbox->appendEvent($evt, Option::none());
        $outbox->appendCommand($cmd, Option::none());
        $outbox->flush();

        self::assertCount(1, $this->cmdBus->recordedEnvelopes());
        self::assertCount(1, $this->evtBus->recordedEnvelopes());
    }

    #[Test]
    public function discardPreventsFlushFromDispatching(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $outbox->appendCommand(new class () implements Command {}, Option::none());
        $outbox->appendEvent(new class () implements DomainEvent {}, Option::none());
        $outbox->discard();
        $outbox->flush();

        self::assertSame([], $this->cmdBus->recordedEnvelopes());
        self::assertSame([], $this->evtBus->recordedEnvelopes());
    }

    #[Test]
    public function flushClearsBufferSoSecondFlushIsEmpty(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $outbox->appendCommand(new class () implements Command {}, Option::none());
        $outbox->flush();
        $outbox->flush();

        self::assertCount(1, $this->cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function producerSuppliedIdIsHonouredOnCommand(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $id = MessageId::generate();
        $outbox->appendCommand(new class () implements Command {}, Option::some($id));
        $outbox->flush();

        self::assertTrue($this->cmdBus->recordedEnvelopes()[0]->metadata->id->equals($id));
    }

    #[Test]
    public function producerSuppliedIdIsHonouredOnEvent(): void
    {
        $outbox = $this->createOutbox($this->cmdBus, $this->evtBus);

        $id = MessageId::generate();
        $outbox->appendEvent(new class () implements DomainEvent {}, Option::some($id));
        $outbox->flush();

        self::assertTrue($this->evtBus->recordedEnvelopes()[0]->metadata->id->equals($id));
    }

    protected function setUp(): void
    {
        $this->cmdBus = new RecordingEnvelopedCommandBus();
        $this->evtBus = new RecordingEnvelopedEventBus();
    }
}
