<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Outbox;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\InMemoryOutbox;
use Monadial\Nexus\Ddd\Messaging\Outbox\InMemoryUnitOfWork;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(InMemoryUnitOfWork::class)]
final class InMemoryUnitOfWorkTest extends TestCase
{
    #[Test]
    public function commitFlushesOutbox(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $outbox = new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($outbox);

        $uow->begin();
        $uow->outbox()->appendCommand(new class implements Command {}, Option::none());
        $uow->commit();

        self::assertCount(1, $cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function rollbackDiscardsOutbox(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $outbox = new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($outbox);

        $uow->begin();
        $uow->outbox()->appendCommand(new class implements Command {}, Option::none());
        $uow->rollback();

        self::assertSame([], $cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function outboxReturnsSameOutboxInstance(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $outbox = new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($outbox);

        self::assertSame($outbox, $uow->outbox());
    }
}
