<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorTag;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

#[CoversClass(Props::class)]
final class PropsTest extends TestCase
{
    #[Test]
    public function fromBehaviorCreatesPropsWithDefaults(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);

        $props = Props::fromBehavior($behavior);

        self::assertSame($behavior, $props->behavior);
        self::assertFalse($props->mailbox->bounded);
        self::assertTrue($props->supervision->isNone());
    }

    #[Test]
    public function withMailboxReturnsNewPropsWithCustomMailbox(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);
        $original = Props::fromBehavior($behavior);

        $bounded = MailboxConfig::bounded(100, OverflowStrategy::DropNewest);
        $updated = $original->withMailbox($bounded);

        // Original is unchanged (immutability)
        self::assertFalse($original->mailbox->bounded);

        // Updated has new mailbox
        self::assertTrue($updated->mailbox->bounded);
        self::assertSame(100, $updated->mailbox->capacity);
        self::assertSame(OverflowStrategy::DropNewest, $updated->mailbox->strategy);

        // Behavior is preserved
        self::assertSame($behavior, $updated->behavior);
        self::assertTrue($updated->supervision->isNone());
    }

    #[Test]
    public function withSupervisionReturnsNewPropsWithStrategy(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);
        $original = Props::fromBehavior($behavior);

        $strategy = new stdClass();
        $updated = $original->withSupervision($strategy);

        // Original is unchanged (immutability)
        self::assertTrue($original->supervision->isNone());

        // Updated has supervision
        self::assertTrue($updated->supervision->isSome());
        self::assertSame($strategy, $updated->supervision->get());

        // Behavior and mailbox are preserved
        self::assertSame($behavior, $updated->behavior);
        self::assertFalse($updated->mailbox->bounded);
    }

    #[Test]
    public function immutabilityChainedWithers(): void
    {
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);

        $strategy = new stdClass();
        $mailbox = MailboxConfig::bounded(50);

        $props = Props::fromBehavior($behavior)
            ->withMailbox($mailbox)
            ->withSupervision($strategy);

        self::assertSame($behavior, $props->behavior);
        self::assertTrue($props->mailbox->bounded);
        self::assertSame(50, $props->mailbox->capacity);
        self::assertTrue($props->supervision->isSome());
        self::assertSame($strategy, $props->supervision->get());
    }

    #[Test]
    public function fromFactoryCreatesBehaviorSetup(): void
    {
        $handler = new class implements ActorHandler {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };

        $props = Props::fromFactory(static fn() => $handler);

        self::assertSame(BehaviorTag::Setup, $props->behavior->tag());
        self::assertFalse($props->mailbox->bounded);
        self::assertTrue($props->supervision->isNone());
    }

    #[Test]
    public function fromFactorySupportsWithersChaining(): void
    {
        $handler = new class implements ActorHandler {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };

        $mailbox = MailboxConfig::bounded(50);
        $strategy = new stdClass();

        $props = Props::fromFactory(static fn() => $handler)
            ->withMailbox($mailbox)
            ->withSupervision($strategy);

        self::assertSame(BehaviorTag::Setup, $props->behavior->tag());
        self::assertTrue($props->mailbox->bounded);
        self::assertSame(50, $props->mailbox->capacity);
        self::assertTrue($props->supervision->isSome());
    }

    #[Test]
    public function fromContainerCreatesBehaviorSetup(): void
    {
        $handler = new class implements ActorHandler {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with($handler::class)
            ->willReturn($handler);

        $props = Props::fromContainer($container, $handler::class);

        self::assertSame(BehaviorTag::Setup, $props->behavior->tag());
    }

    #[Test]
    public function fromStatefulFactoryCreatesBehaviorSetup(): void
    {
        $handler = new class implements StatefulActorHandler {
            public function initialState(): int
            {
                return 0;
            }

            public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
            {
                return BehaviorWithState::next($state + 1);
            }
        };

        $props = Props::fromStatefulFactory(static fn() => $handler);

        self::assertSame(BehaviorTag::Setup, $props->behavior->tag());
        self::assertFalse($props->mailbox->bounded);
        self::assertTrue($props->supervision->isNone());
    }

    #[Test]
    public function fromFactoryThrowsActorInitializationExceptionOnBadFactory(): void
    {
        $props = Props::fromFactory(static fn() => throw new RuntimeException('container exploded'));

        $runtime = new TestRuntime();
        $mailbox = $runtime->createMailbox($props->mailbox);

        /** @var Option<\Monadial\Nexus\Core\Actor\ActorRef<object>> $none */
        $none = Option::none();

        $cell = new ActorCell(
            $props->behavior,
            ActorPath::fromString('/user/test'),
            $mailbox,
            $runtime,
            $none,
            SupervisionStrategy::oneForOne(),
            new TestClock(),
            new NullLogger(),
            new DeadLetterRef(),
        );

        $this->expectException(ActorInitializationException::class);
        $this->expectExceptionMessage('container exploded');
        $cell->start();
    }
}
