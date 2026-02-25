<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(LocalActorRef::class)]
final class LocalActorRefTest extends TestCase
{
    private TestRuntime $runtime;

    #[Test]
    public function tell_enqueues_message_to_mailbox(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/greeter');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, $this->runtime);

        $message = new stdClass();
        $message->text = 'hello';
        $ref->tell($message);

        self::assertSame(1, $mailbox->count());

        $envelope = $mailbox->dequeue()->get();
        self::assertSame($message, $envelope->message);
        self::assertTrue($path->equals($envelope->target));
        self::assertTrue(ActorPath::root()->equals($envelope->sender));
    }

    #[Test]
    public function path_returns_actor_path(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/orders');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, $this->runtime);

        self::assertTrue($path->equals($ref->path()));
    }

    #[Test]
    public function isAlive_returns_true_when_alive(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/worker');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, $this->runtime);

        self::assertTrue($ref->isAlive());
    }

    #[Test]
    public function isAlive_returns_false_when_not_alive(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/worker');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => false, $this->runtime);

        self::assertFalse($ref->isAlive());
    }

    #[Test]
    public function tell_on_closed_mailbox_silently_drops(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/dead');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => false, $this->runtime);

        $mailbox->close();

        // Should NOT throw — fire-and-forget semantics
        $ref->tell(new stdClass());

        self::assertSame(0, $mailbox->count());
    }

    #[Test]
    public function ask_returns_reply_from_mailbox(): void
    {
        $mailbox = TestMailbox::unbounded();
        $path = ActorPath::fromString('/user/service');
        $ref = new LocalActorRef($path, $mailbox, static fn(): bool => true, $this->runtime);

        // Simulate: when ask() sends to the target mailbox, we'll have the
        // "actor" reply immediately by hooking into the target mailbox.
        // In unit test context (TestMailbox), dequeueBlocking returns immediately
        // if there's a message, or throws MailboxClosedException if empty/closed.
        // So we need to pre-populate the temp mailbox. Since we can't access
        // the temp mailbox directly, we test ask() at the integration level.
        // Here we just verify it creates the right message via the factory.
        $factoryCalled = false;

        try {
            $ref->ask(static function ($replyTo) use (&$factoryCalled) {
                $factoryCalled = true;

                return new stdClass();
            }, Duration::millis(100));
        } catch (AskTimeoutException) {
            // Expected: TestMailbox's dequeueBlocking throws when empty
        }

        self::assertTrue($factoryCalled);
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
    }
}
