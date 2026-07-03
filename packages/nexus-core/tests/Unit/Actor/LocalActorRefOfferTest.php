<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(LocalActorRef::class)]
final class LocalActorRefOfferTest extends TestCase
{
    #[Test]
    public function offerReturnsAcceptedWhenMailboxAccepts(): void
    {
        $ref = $this->makeRef();

        self::assertInstanceOf(BackpressureCapable::class, $ref);
        self::assertSame(EnqueueResult::Accepted, $ref->offer(new stdClass()));
    }

    #[Test]
    public function offerReturnsDroppedWhenMailboxIsClosed(): void
    {
        [$ref, $mailbox] = $this->makeRefWithMailbox();
        $mailbox->close();

        self::assertSame(EnqueueResult::Dropped, $ref->offer(new stdClass()));
    }

    private function makeRef(): LocalActorRef
    {
        return new LocalActorRef(
            ActorPath::fromString('/user/test'),
            TestMailbox::unbounded(),
            static fn(): bool => true,
            new TestRuntime(),
            new NoopObservability(),
        );
    }

    /** @return array{LocalActorRef, TestMailbox} */
    private function makeRefWithMailbox(): array
    {
        $mailbox = TestMailbox::unbounded();

        return [
            new LocalActorRef(
                ActorPath::fromString('/user/test'),
                $mailbox,
                static fn(): bool => true,
                new TestRuntime(),
                new NoopObservability(),
            ),
            $mailbox,
        ];
    }
}
