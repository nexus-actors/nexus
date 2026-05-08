<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Staging\MessageStaging;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-api
 *
 * Contract test for MessageStaging implementations. Extend this class
 * and implement createStaging() to verify a new staging impl satisfies
 * the contract without duplicating test logic.
 */
abstract class MessageStagingContractTest extends TestCase
{
    protected RecordingEnvelopedCommandBus $cmdBus;

    protected RecordingEnvelopedEventBus $evtBus;

    abstract protected function createStaging(
        RecordingEnvelopedCommandBus $cmdBus,
        RecordingEnvelopedEventBus $evtBus,
    ): MessageStaging;

    protected function setUp(): void
    {
        $this->cmdBus = new RecordingEnvelopedCommandBus();
        $this->evtBus = new RecordingEnvelopedEventBus();
    }

    #[Test]
    public function flushDispatchesInFifoOrder(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $cmd1 = new class () implements Command {};
        $cmd2 = new class () implements Command {};
        $staging->appendCommand($cmd1, Option::none());
        $staging->appendCommand($cmd2, Option::none());
        $staging->flush();

        $envelopes = $this->cmdBus->recordedEnvelopes();
        self::assertCount(2, $envelopes);
        self::assertSame($cmd1, $envelopes[0]->message);
        self::assertSame($cmd2, $envelopes[1]->message);
    }

    #[Test]
    public function flushDispatchesCommandsBeforeEvents(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $evt = new class () implements DomainEvent {};
        $cmd = new class () implements Command {};
        $staging->appendEvent($evt, Option::none());
        $staging->appendCommand($cmd, Option::none());
        $staging->flush();

        self::assertCount(1, $this->cmdBus->recordedEnvelopes());
        self::assertCount(1, $this->evtBus->recordedEnvelopes());
    }

    #[Test]
    public function discardPreventsFlushFromDispatching(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $staging->appendCommand(new class () implements Command {}, Option::none());
        $staging->appendEvent(new class () implements DomainEvent {}, Option::none());
        $staging->discard();
        $staging->flush();

        self::assertSame([], $this->cmdBus->recordedEnvelopes());
        self::assertSame([], $this->evtBus->recordedEnvelopes());
    }

    #[Test]
    public function flushClearsBufferSoSecondFlushIsEmpty(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $staging->appendCommand(new class () implements Command {}, Option::none());
        $staging->flush();
        $staging->flush();

        self::assertCount(1, $this->cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function producerSuppliedIdIsHonouredOnCommand(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $id = MessageId::generate();
        $staging->appendCommand(new class () implements Command {}, Option::some($id));
        $staging->flush();

        self::assertTrue($this->cmdBus->recordedEnvelopes()[0]->metadata->id->equals($id));
    }

    #[Test]
    public function producerSuppliedIdIsHonouredOnEvent(): void
    {
        $staging = $this->createStaging($this->cmdBus, $this->evtBus);

        $id = MessageId::generate();
        $staging->appendEvent(new class () implements DomainEvent {}, Option::some($id));
        $staging->flush();

        self::assertTrue($this->evtBus->recordedEnvelopes()[0]->metadata->id->equals($id));
    }
}
