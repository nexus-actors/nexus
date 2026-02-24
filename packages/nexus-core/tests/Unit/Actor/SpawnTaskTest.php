<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorState;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\TaskContext;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestLogger;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final readonly class SpawnTaskMessage
{
    public function __construct(public string $value) {}
}

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(ActorCell::class)]
final class SpawnTaskTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private TestLogger $logger;

    #[Test]
    public function spawnTask_receives_task_context_and_can_tell_messages_back(): void
    {
        /**
         * @var Behavior<SpawnTaskMessage>
         * @psalm-suppress InvalidArgument
         */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof SpawnTaskMessage && $msg->value === 'spawn') {
                    $ctx->spawnTask(static function (TaskContext $task): void {
                        $task->tell(new SpawnTaskMessage('from-task'));
                    });
                }

                return Behavior::same();
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();

        // Trigger task spawn
        $cell->processMessage(Envelope::of(
            new SpawnTaskMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // The runtime stores spawned callables — execute the task
        $spawned = $this->runtime->spawnedActors();
        self::assertNotEmpty($spawned);
        /** @psalm-suppress PossiblyNullArrayOffset */
        $taskCallable = $spawned[array_key_last($spawned)];
        $taskCallable();

        // The task should have sent a message to the parent's mailbox
        self::assertSame(1, $mailbox->count());
        $envelope = $mailbox->dequeue()->get();
        /** @psalm-suppress PossiblyNullPropertyFetch */
        assert($envelope->message instanceof SpawnTaskMessage);
        self::assertSame('from-task', $envelope->message->value);
    }

    #[Test]
    public function spawnTask_is_cancelled_when_actor_stops(): void
    {
        $taskHandle = null;

        /**
         * @var Behavior<SpawnTaskMessage>
         * @psalm-suppress InvalidArgument
         */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$taskHandle): Behavior {
                if ($msg instanceof SpawnTaskMessage && $msg->value === 'spawn') {
                    $taskHandle = $ctx->spawnTask(static function (TaskContext $task): void {
                        while (!$task->isCancelled()) {
                            // busy loop — in real code this would be I/O
                        }
                    });
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Spawn the task
        $cell->processMessage(Envelope::of(
            new SpawnTaskMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        self::assertNotNull($taskHandle);
        self::assertFalse($taskHandle->isCancelled());

        // Stop the actor — should cancel the task
        $cell->initiateStop();

        self::assertTrue($taskHandle->isCancelled());
        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    #[Test]
    public function spawnTask_exception_is_logged_and_actor_stays_alive(): void
    {
        /**
         * @var Behavior<SpawnTaskMessage>
         * @psalm-suppress InvalidArgument
         */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof SpawnTaskMessage && $msg->value === 'spawn') {
                    /** @psalm-suppress UnusedClosureParam */
                    $ctx->spawnTask(static function (TaskContext $task): void {
                        throw new RuntimeException('task failed');
                    });
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Spawn the task
        $cell->processMessage(Envelope::of(
            new SpawnTaskMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Execute the task — it throws
        $spawned = $this->runtime->spawnedActors();
        /** @psalm-suppress PossiblyNullArrayOffset */
        $taskCallable = $spawned[array_key_last($spawned)];
        $taskCallable();

        // Actor should still be alive
        self::assertTrue($cell->isAlive());
        self::assertSame(ActorState::Running, $cell->actorState());

        // Error should be logged
        self::assertTrue($this->logger->hasLogMatching('error', 'task failed'));
    }

    #[Test]
    public function spawnTask_returns_cancellable(): void
    {
        /**
         * @var Behavior<SpawnTaskMessage>
         * @psalm-suppress InvalidArgument
         */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof SpawnTaskMessage && $msg->value === 'spawn') {
                    /** @psalm-suppress UnusedClosureParam */
                    $handle = $ctx->spawnTask(static function (TaskContext $task): void {
                        // no-op
                    });

                    // Manual cancellation
                    $handle->cancel();
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage(Envelope::of(
            new SpawnTaskMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Actor should still be running
        self::assertTrue($cell->isAlive());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
        $this->logger = new TestLogger();
    }

    /**
     * @template T of object
     * @param Behavior<T> $behavior
     * @return ActorCell<T>
     */
    private function createCell(Behavior $behavior, ?ActorPath $path = null, ?TestMailbox $mailbox = null): ActorCell
    {
        $path ??= ActorPath::fromString('/user/test');
        $mailbox ??= TestMailbox::unbounded();

        /** @var Option<\Monadial\Nexus\Core\Actor\ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $path,
            $mailbox,
            $this->runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $this->runtime->clock(),
            $this->logger,
            $this->deadLetters,
        );
    }
}
