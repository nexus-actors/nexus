<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Props::class)]
final class PropsTest extends TestCase
{
    #[Test]
    public function fromBehaviorCreatesPropsWithDefaults(): void
    {
        $handler = static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same();
        $behavior = Behavior::receive($handler);

        $props = Props::fromBehavior($behavior);

        self::assertSame($behavior, $props->behavior);
        self::assertFalse($props->mailbox->bounded);
        self::assertTrue($props->supervision->isNone());
    }

    #[Test]
    public function withMailboxReturnsNewPropsWithCustomMailbox(): void
    {
        $handler = static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same();
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
        $handler = static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same();
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
        $handler = static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same();
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
}
