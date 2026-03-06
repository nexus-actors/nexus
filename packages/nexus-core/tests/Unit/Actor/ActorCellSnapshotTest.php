<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ActorCell::class)]
final class ActorCellSnapshotTest extends TestCase
{
    #[Test]
    public function snapshot_reflects_mailbox_config(): void
    {
        $cell = $this->makeCell(MailboxConfig::bounded(500));
        $cell->start();

        $snap = $cell->snapshot();

        self::assertInstanceOf(ActorSnapshot::class, $snap);
        self::assertSame('/user/orders', $snap->path);
        self::assertTrue($snap->alive);
        self::assertSame(0, $snap->mailboxDepth);
        self::assertSame(500, $snap->mailboxCapacity);
        self::assertTrue($snap->mailboxBounded);
        self::assertSame([], $snap->children);
    }

    #[Test]
    public function snapshot_includes_children_recursively(): void
    {
        $cell = $this->makeCell(MailboxConfig::unbounded());
        $cell->start();

        $cell->spawn(
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
            'processor',
        );

        $snap = $cell->snapshot();

        self::assertCount(1, $snap->children);
        self::assertSame('/user/orders/processor', $snap->children[0]->path);
    }

    private function makeCell(MailboxConfig $config): ActorCell
    {
        $runtime = new TestRuntime();

        return new ActorCell(
            Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ActorPath::fromString('/user/orders'),
            new TestMailbox($config),
            $config,
            $runtime,
            null,
            SupervisionStrategy::oneForOne(),
            $runtime->clock(),
            new NullLogger(),
            new DeadLetterRef(),
        );
    }
}
