<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(LocalActorRef::class)]
final class LocalActorRefTest extends TestCase
{
    #[Test]
    public function tell_enqueues_message_to_mailbox(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/greeter');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, new TestRuntime());

        $message = new stdClass();
        $message->text = 'hello';
        $ref->tell($message);

        self::assertSame(1, $mailbox->count());

        $envelope = $mailbox->dequeue()->get();
        self::assertSame($message, $envelope->message);
        self::assertTrue($path->equals($envelope->target));
        self::assertTrue(ActorPath::root()->equals($envelope->sender));
        self::assertNotSame('', $envelope->requestId);
        self::assertSame($envelope->requestId, $envelope->correlationId);
        self::assertSame($envelope->requestId, $envelope->causationId);
    }

    #[Test]
    public function path_returns_actor_path(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/orders');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, new TestRuntime());

        self::assertTrue($path->equals($ref->path()));
    }

    #[Test]
    public function isAlive_returns_true_when_alive(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/worker');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, new TestRuntime());

        self::assertTrue($ref->isAlive());
    }

    #[Test]
    public function isAlive_returns_false_when_not_alive(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/worker');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => false, new TestRuntime());

        self::assertFalse($ref->isAlive());
    }

    #[Test]
    public function tell_on_closed_mailbox_silently_drops(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/dead');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => false, new TestRuntime());

        $mailbox->close();

        // Should NOT throw — fire-and-forget semantics
        $ref->tell(new stdClass());

        self::assertSame(0, $mailbox->count());
    }

    #[Test]
    public function ask_enqueues_message_and_returns_future(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/service');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, new TestRuntime());

        $message = new stdClass();
        $future = $ref->ask($message, Duration::seconds(5));

        self::assertSame(1, $mailbox->count());

        $envelope = $mailbox->dequeue()->get();
        self::assertSame($message, $envelope->message);
        self::assertNotNull($envelope->senderRef);
        self::assertNotSame('', $envelope->requestId);
        self::assertSame($envelope->requestId, $envelope->correlationId);
        self::assertSame($envelope->requestId, $envelope->causationId);
        self::assertFalse($future->isResolved());
    }
}
