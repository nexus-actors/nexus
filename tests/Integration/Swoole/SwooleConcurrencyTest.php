<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\WorkItem;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * Tests unique to the Swoole runtime that verify true coroutine concurrency.
 */
final class SwooleConcurrencyTest extends TestCase
{
    /**
     * Test: 5 workers with Coroutine::sleep(0.05) each complete in < 200ms.
     *
     * If actors run concurrently (as Swoole coroutines), 5 x 50ms sleeps
     * should overlap and total elapsed time should be well under 200ms.
     * If they ran sequentially, it would take ~250ms.
     */
    public function testConcurrentActorsOverlap(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<float> $completionTimes */
        $completionTimes = [];
        $startTime = 0.0;

        $system = ActorSystem::create('concurrency-test', $runtime);

        /** @var list<ActorRef<object>> $refs */
        $refs = [];

        for ($i = 0; $i < 5; $i++) {
            /** @var Behavior<object> $workerBehavior */
            $workerBehavior = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$completionTimes): Behavior {
                    Coroutine::sleep(0.05);
                    $completionTimes[] = microtime(true);

                    return Behavior::same();
                },
            );

            $refs[] = $system->spawn(Props::fromBehavior($workerBehavior), "worker-{$i}");
        }

        // Schedule message sending inside Co\run (Swoole Channel requires coroutine context)
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($refs, &$startTime): void {
            $startTime = microtime(true);

            foreach ($refs as $i => $ref) {
                $ref->tell(new WorkItem($i));
            }
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(5, $completionTimes);

        // Measure from message send to last worker completion
        $lastCompletion = max($completionTimes);
        $elapsed = ($lastCompletion - $startTime) * 1000;
        self::assertLessThan(200.0, $elapsed, "Expected concurrent execution but took {$elapsed}ms");
    }

    /**
     * Test: All worker IDs are present in results, proving each ran.
     */
    public function testAllWorkersComplete(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<int> $results */
        $results = [];

        $system = ActorSystem::create('all-workers-test', $runtime);

        /** @var list<ActorRef<object>> $refs */
        $refs = [];

        for ($i = 0; $i < 5; $i++) {
            $workerId = $i;

            /** @var Behavior<object> $workerBehavior */
            $workerBehavior = Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$results, $workerId): Behavior {
                    Coroutine::sleep(0.01);
                    $results[] = $workerId;

                    return Behavior::same();
                },
            );

            $refs[] = $system->spawn(Props::fromBehavior($workerBehavior), "worker-{$i}");
        }

        // Schedule message sending inside Co\run
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($refs): void {
            foreach ($refs as $i => $ref) {
                $ref->tell(new WorkItem($i));
            }
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        sort($results);
        self::assertSame([0, 1, 2, 3, 4], $results);
    }
}
