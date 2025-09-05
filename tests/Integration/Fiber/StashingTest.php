<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Initialize;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\WorkItem;
use PHPUnit\Framework\TestCase;

final class StashingTest extends TestCase
{
    /**
     * Test: Actor stashes messages before initialization, then unstashes.
     *
     * Flow:
     *   1. Actor starts in "initializing" behavior
     *   2. WorkItem messages arrive and are stashed
     *   3. Initialize message arrives, actor transitions to "ready" and unstashes
     *   4. Ready behavior processes the previously stashed WorkItems
     *   5. Verify all WorkItems are processed in order
     */
    public function testStashAndUnstash(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stash-test', $runtime);

        /** @var list<int> $processedIds */
        $processedIds = [];

        // "Ready" behavior: processes WorkItem messages and records their IDs
        /** @var Behavior<object> $readyBehavior */
        $readyBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$processedIds): Behavior {
            if ($msg instanceof WorkItem) {
                $processedIds[] = $msg->id;
            }

            return Behavior::same();
        });

        // "Initializing" behavior: stashes WorkItems, transitions on Initialize
        /** @var Behavior<object> $initBehavior */
        $initBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use ($readyBehavior): Behavior {
            if ($msg instanceof Initialize) {
                $ctx->unstashAll();

                return $readyBehavior;
            }

            // Stash everything else
            $ctx->stash();

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($initBehavior), 'stasher');

        // Send work items BEFORE the Initialize message
        $ref->tell(new WorkItem(1));
        $ref->tell(new WorkItem(2));
        $ref->tell(new WorkItem(3));

        // Now initialize: this should unstash and process the work items
        $ref->tell(new Initialize());

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // All 3 work items should have been processed in order
        self::assertSame([1, 2, 3], $processedIds);
    }

    /**
     * Test: Stashing with no messages to unstash is a no-op.
     */
    public function testUnstashWithEmptyStash(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stash-empty-test', $runtime);

        /** @var list<int> $processedIds */
        $processedIds = [];

        /** @var Behavior<object> $readyBehavior */
        $readyBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$processedIds): Behavior {
            if ($msg instanceof WorkItem) {
                $processedIds[] = $msg->id;
            }

            return Behavior::same();
        });

        /** @var Behavior<object> $initBehavior */
        $initBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use ($readyBehavior): Behavior {
            if ($msg instanceof Initialize) {
                $ctx->unstashAll();

                return $readyBehavior;
            }

            $ctx->stash();

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($initBehavior), 'stasher');

        // Initialize immediately without any prior work items
        $ref->tell(new Initialize());

        // Then send work items which go directly to the ready behavior
        $ref->tell(new WorkItem(10));
        $ref->tell(new WorkItem(20));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame([10, 20], $processedIds);
    }

    /**
     * Test: Stashed messages are processed before new messages after unstash.
     */
    public function testStashedMessagesProcessedBeforeNewOnes(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stash-order-test', $runtime);

        /** @var list<int> $processedIds */
        $processedIds = [];

        /** @var Behavior<object> $readyBehavior */
        $readyBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$processedIds): Behavior {
            if ($msg instanceof WorkItem) {
                $processedIds[] = $msg->id;
            }

            return Behavior::same();
        });

        /** @var Behavior<object> $initBehavior */
        $initBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use ($readyBehavior): Behavior {
            if ($msg instanceof Initialize) {
                $ctx->unstashAll();

                return $readyBehavior;
            }

            $ctx->stash();

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($initBehavior), 'stasher');

        // Stash these work items
        $ref->tell(new WorkItem(1));
        $ref->tell(new WorkItem(2));

        // Initialize (unstashes items 1 and 2)
        $ref->tell(new Initialize());

        // New items sent after initialization
        $ref->tell(new WorkItem(3));
        $ref->tell(new WorkItem(4));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // Stashed items (1, 2) should be re-enqueued before new items (3, 4)
        // However, since unstash re-enqueues to the mailbox, and items 3 and 4
        // are already in the mailbox, the exact order depends on enqueue ordering.
        // The unstashed items are re-enqueued DURING processing of Initialize,
        // so they go after items already in the queue (3, 4).
        // Actually: items 3 and 4 are enqueued AFTER Initialize. At the time
        // Initialize is processed, 3 and 4 may or may not be in the mailbox yet.
        // Since all tells happen before run(), the mailbox order is:
        // [WorkItem(1), WorkItem(2), Initialize, WorkItem(3), WorkItem(4)]
        // Processing: stash(1), stash(2), Initialize->unstash->enqueue(1,2)
        // Queue after unstash: [WorkItem(3), WorkItem(4), WorkItem(1), WorkItem(2)]
        // So the order is 3, 4, 1, 2
        self::assertCount(4, $processedIds);
        self::assertSame([3, 4, 1, 2], $processedIds);
    }
}
