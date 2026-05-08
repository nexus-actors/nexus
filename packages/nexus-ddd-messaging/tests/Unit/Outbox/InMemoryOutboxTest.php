<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Outbox;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\InMemoryOutbox;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(InMemoryOutbox::class)]
final class InMemoryOutboxTest extends TestCase
{
    #[Test]
    public function flushDispatchesCommandsThenEventsExactlyOnce(): void
    {
        $outbox = $this->newOutbox($cmdBus, $evtBus);

        $cmd = new class implements Command {};
        $evt = new class implements DomainEvent {};
        $outbox->appendCommand($cmd, Option::none());
        $outbox->appendEvent($evt, Option::none());
        $outbox->flush();

        self::assertCount(1, $cmdBus->recordedEnvelopes());
        self::assertCount(1, $evtBus->recordedEnvelopes());
        self::assertSame($cmd, $cmdBus->recordedEnvelopes()[0]->message);
        self::assertSame($evt, $evtBus->recordedEnvelopes()[0]->message);
    }

    #[Test]
    public function discardDropsEverythingStaged(): void
    {
        $outbox = $this->newOutbox($cmdBus, $evtBus);

        $outbox->appendCommand(new class implements Command {}, Option::none());
        $outbox->appendEvent(new class implements DomainEvent {}, Option::none());
        $outbox->discard();
        $outbox->flush();

        self::assertSame([], $cmdBus->recordedEnvelopes());
        self::assertSame([], $evtBus->recordedEnvelopes());
    }

    #[Test]
    public function honoursProducerSuppliedMessageId(): void
    {
        $outbox = $this->newOutbox($cmdBus, $evtBus);

        $producerId = MessageId::generate();
        $outbox->appendCommand(new class implements Command {}, Option::some($producerId));
        $outbox->flush();

        self::assertTrue($cmdBus->recordedEnvelopes()[0]->metadata->id->equals($producerId));
    }

    #[Test]
    public function withoutParentContextEmitsRootMetadataWithProducerId(): void
    {
        $outbox = $this->newOutbox($cmdBus, $evtBus);
        $producerId = MessageId::generate();

        $outbox->appendCommand(new class implements Command {}, Option::some($producerId));
        $outbox->flush();

        $metadata = $cmdBus->recordedEnvelopes()[0]->metadata;
        self::assertTrue($metadata->id->equals($producerId));
        self::assertTrue($metadata->causationId->isNone(), 'no parent context → root has no causation');
        self::assertTrue($metadata->correlationId->isNone(), 'root has no correlation');
        self::assertTrue($metadata->conversationId->isNone(), 'root has no conversation');
    }

    private function newOutbox(
        ?RecordingEnvelopedCommandBus &$cmdBus = null,
        ?RecordingEnvelopedEventBus &$evtBus = null,
    ): InMemoryOutbox {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();

        return new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
    }
}
