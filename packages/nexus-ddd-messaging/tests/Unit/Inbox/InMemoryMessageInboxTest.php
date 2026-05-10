<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Inbox;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\InMemoryMessageInbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryMessageInbox::class)]
final class InMemoryMessageInboxTest extends TestCase
{
    #[Test]
    public function firstReserveSucceedsSecondFails(): void
    {
        $inbox = new InMemoryMessageInbox();
        $id = MessageId::generate();

        self::assertTrue($inbox->tryReserve(self::class, $id));
        self::assertFalse($inbox->tryReserve(self::class, $id));
    }

    #[Test]
    public function reservationsAreScopedPerHandler(): void
    {
        $inbox = new InMemoryMessageInbox();
        $id = MessageId::generate();

        self::assertTrue($inbox->tryReserve('HandlerA', $id));
        self::assertTrue($inbox->tryReserve('HandlerB', $id));
    }

    #[Test]
    public function releaseRevertsReservation(): void
    {
        $inbox = new InMemoryMessageInbox();
        $id = MessageId::generate();

        $inbox->tryReserve(self::class, $id);
        $inbox->release(self::class, $id);

        self::assertTrue($inbox->tryReserve(self::class, $id));
    }

    #[Test]
    public function markCompletedKeepsReservationLocked(): void
    {
        $inbox = new InMemoryMessageInbox();
        $id = MessageId::generate();

        $inbox->tryReserve(self::class, $id);
        $inbox->markCompleted(self::class, $id, Option::none());

        self::assertFalse($inbox->tryReserve(self::class, $id));
    }
}
