<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Staging;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Staging\InMemoryMessageStaging;
use Monadial\Nexus\Ddd\Messaging\Staging\InMemoryUnitOfWork;
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
    public function commitFlushesStaging(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $staging = new InMemoryMessageStaging(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($staging);

        $uow->begin();
        $uow->staging()->appendCommand(new class () implements Command {}, Option::none());
        $uow->commit();

        self::assertCount(1, $cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function rollbackDiscardsStaging(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $staging = new InMemoryMessageStaging(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($staging);

        $uow->begin();
        $uow->staging()->appendCommand(new class () implements Command {}, Option::none());
        $uow->rollback();

        self::assertSame([], $cmdBus->recordedEnvelopes());
    }

    #[Test]
    public function stagingReturnsSameStagingInstance(): void
    {
        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $staging = new InMemoryMessageStaging(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
        $uow = new InMemoryUnitOfWork($staging);

        self::assertSame($staging, $uow->staging());
    }
}
