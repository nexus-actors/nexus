<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-api
 *
 * Contract test for MessageInbox implementations. Extend and implement
 * createInbox() to verify a new inbox impl satisfies the contract.
 */
abstract class MessageInboxContractTest extends TestCase
{
    abstract protected function createInbox(): MessageInbox;

    #[Test]
    public function tryReserveIsIdempotentOnRepeat(): void
    {
        $inbox = $this->createInbox();
        $id = MessageId::generate();

        self::assertTrue($inbox->tryReserve(self::class, $id));
        self::assertFalse($inbox->tryReserve(self::class, $id));
    }

    #[Test]
    public function reservationIsPerHandler(): void
    {
        $inbox = $this->createInbox();
        $id = MessageId::generate();

        self::assertTrue($inbox->tryReserve('HandlerA', $id));
        self::assertTrue($inbox->tryReserve('HandlerB', $id));
    }

    #[Test]
    public function releaseAllowsSubsequentReserve(): void
    {
        $inbox = $this->createInbox();
        $id = MessageId::generate();

        $inbox->tryReserve(self::class, $id);
        $inbox->release(self::class, $id);

        self::assertTrue($inbox->tryReserve(self::class, $id));
    }

    #[Test]
    public function markCompletedDoesNotReleaseReservation(): void
    {
        $inbox = $this->createInbox();
        $id = MessageId::generate();

        $inbox->tryReserve(self::class, $id);
        $inbox->markCompleted(self::class, $id, Option::none());

        self::assertFalse($inbox->tryReserve(self::class, $id));
    }
}
