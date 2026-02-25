<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\TaskContext;
use Monadial\Nexus\Core\Tests\Support\TestLogger;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class TaskMessage
{
    public function __construct(public string $value) {}
}

#[CoversClass(TaskContext::class)]
final class TaskContextTest extends TestCase
{
    #[Test]
    public function tell_forwards_to_parent_ref(): void
    {
        $mailbox = TestMailbox::unbounded();
        $parentRef = new LocalActorRef(
            ActorPath::fromString('/user/parent'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
        );
        $logger = new TestLogger();

        $taskCtx = new TaskContext($parentRef, $logger);
        $taskCtx->tell(new TaskMessage('hello'));

        self::assertSame(1, $mailbox->count());
        $envelope = $mailbox->dequeue()->get();
        /** @psalm-suppress PossiblyNullPropertyFetch */
        assert($envelope->message instanceof TaskMessage);
        self::assertSame('hello', $envelope->message->value);
    }

    #[Test]
    public function cancel_sets_cancelled_flag(): void
    {
        $mailbox = TestMailbox::unbounded();
        $parentRef = new LocalActorRef(
            ActorPath::fromString('/user/parent'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
        );
        $logger = new TestLogger();

        $taskCtx = new TaskContext($parentRef, $logger);

        self::assertFalse($taskCtx->isCancelled());
        $taskCtx->cancel();
        self::assertTrue($taskCtx->isCancelled());
    }

    #[Test]
    public function double_cancel_is_idempotent(): void
    {
        $mailbox = TestMailbox::unbounded();
        $parentRef = new LocalActorRef(
            ActorPath::fromString('/user/parent'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
        );
        $logger = new TestLogger();

        $taskCtx = new TaskContext($parentRef, $logger);

        $taskCtx->cancel();
        $taskCtx->cancel();
        self::assertTrue($taskCtx->isCancelled());
    }

    #[Test]
    public function log_returns_logger(): void
    {
        $mailbox = TestMailbox::unbounded();
        $parentRef = new LocalActorRef(
            ActorPath::fromString('/user/parent'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
        );
        $logger = new TestLogger();

        $taskCtx = new TaskContext($parentRef, $logger);

        self::assertSame($logger, $taskCtx->log());
    }
}
