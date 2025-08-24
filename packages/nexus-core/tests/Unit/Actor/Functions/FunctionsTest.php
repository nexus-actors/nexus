<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor\Functions;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Core\Actor\Functions\withMailbox;
use function Monadial\Nexus\Core\Actor\Functions\withSupervision;

#[CoversFunction('Monadial\Nexus\Core\Actor\Functions\withMailbox')]
#[CoversFunction('Monadial\Nexus\Core\Actor\Functions\withSupervision')]
final class FunctionsTest extends TestCase
{
    #[Test]
    public function withMailboxReturnsClosure(): void
    {
        $config = MailboxConfig::bounded(100, OverflowStrategy::DropNewest);
        $fn = withMailbox($config);

        self::assertInstanceOf(\Closure::class, $fn);
    }

    #[Test]
    public function withMailboxAppliesConfig(): void
    {
        $behavior = Behavior::receive(static fn($ctx, $msg) => Behavior::same());
        $props = Props::fromBehavior($behavior);
        $config = MailboxConfig::bounded(100, OverflowStrategy::DropNewest);

        $result = withMailbox($config)($props);

        self::assertSame($config, $result->mailbox);
    }

    #[Test]
    public function withSupervisionReturnsClosure(): void
    {
        $strategy = SupervisionStrategy::oneForOne();
        $fn = withSupervision($strategy);

        self::assertInstanceOf(\Closure::class, $fn);
    }

    #[Test]
    public function withSupervisionAppliesStrategy(): void
    {
        $behavior = Behavior::receive(static fn($ctx, $msg) => Behavior::same());
        $props = Props::fromBehavior($behavior);
        $strategy = SupervisionStrategy::oneForOne();

        $result = withSupervision($strategy)($props);

        self::assertTrue($result->supervision->isSome());
        self::assertSame($strategy, $result->supervision->get());
    }

    #[Test]
    public function pipeComposition(): void
    {
        $behavior = Behavior::receive(static fn($ctx, $msg) => Behavior::same());
        $config = MailboxConfig::bounded(500, OverflowStrategy::DropOldest);
        $strategy = SupervisionStrategy::allForOne();

        // Simulate pipe: $behavior |> Props::fromBehavior(...) |> withMailbox($config) |> withSupervision($strategy)
        $props = Props::fromBehavior($behavior);
        $props = withMailbox($config)($props);
        $props = withSupervision($strategy)($props);

        self::assertSame($config, $props->mailbox);
        self::assertTrue($props->supervision->isSome());
        self::assertSame($strategy, $props->supervision->get());
    }
}
