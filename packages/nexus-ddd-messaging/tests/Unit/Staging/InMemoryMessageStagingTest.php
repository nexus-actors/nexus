<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Staging;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Staging\InMemoryMessageStaging;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(InMemoryMessageStaging::class)]
final class InMemoryMessageStagingTest extends TestCase
{
    #[Test]
    public function flushDispatchesCommandsThenEventsExactlyOnce(): void
    {
        $staging = $this->newStaging($cmdBus, $evtBus);

        $cmd = new class () implements Command {};
        $evt = new class () implements DomainEvent {};
        $staging->appendCommand($cmd, Option::none());
        $staging->appendEvent($evt, Option::none());
        $staging->flush();

        self::assertCount(1, $cmdBus->recordedEnvelopes());
        self::assertCount(1, $evtBus->recordedEnvelopes());
        self::assertSame($cmd, $cmdBus->recordedEnvelopes()[0]->message);
        self::assertSame($evt, $evtBus->recordedEnvelopes()[0]->message);
    }

    #[Test]
    public function discardDropsEverythingStaged(): void
    {
        $staging = $this->newStaging($cmdBus, $evtBus);

        $staging->appendCommand(new class () implements Command {}, Option::none());
        $staging->appendEvent(new class () implements DomainEvent {}, Option::none());
        $staging->discard();
        $staging->flush();

        self::assertSame([], $cmdBus->recordedEnvelopes());
        self::assertSame([], $evtBus->recordedEnvelopes());
    }

    #[Test]
    public function honoursProducerSuppliedMessageId(): void
    {
        $staging = $this->newStaging($cmdBus, $evtBus);

        $producerId = MessageId::generate();
        $staging->appendCommand(new class () implements Command {}, Option::some($producerId));
        $staging->flush();

        self::assertTrue($cmdBus->recordedEnvelopes()[0]->metadata->id->equals($producerId));
    }

    private function newStaging(
        ?RecordingEnvelopedCommandBus &$cmdBus = null,
        ?RecordingEnvelopedEventBus &$evtBus = null,
    ): InMemoryMessageStaging {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();

        return new InMemoryMessageStaging(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
    }
}
