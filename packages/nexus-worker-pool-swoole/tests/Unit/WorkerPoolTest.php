<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(WorkerPool::class)]
final class WorkerPoolTest extends TestCase
{
    #[Test]
    public function builderMethodsReturnNewInstances(): void
    {
        $base = WorkerPool::withThreads(2);

        self::assertNotSame($base, $base->withName('x'));
        self::assertNotSame($base, $base->withLogger(NullLogger::class));
        self::assertNotSame($base, $base->withLoggerFactory(static fn(): NullLogger => new NullLogger()));
        self::assertNotSame($base, $base->actor('a', WorkerPoolTestActor::class));
        self::assertNotSame($base, $base->stateful('b', WorkerPoolTestStatefulActor::class));
        self::assertNotSame($base, $base->behavior('c', static fn(): Behavior => Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        )));
        self::assertNotSame($base, $base->configure(static function (WorkerNode $n): void {}));
    }

    #[Test]
    public function actorStepSpawnsLocalActorOnNode(): void
    {
        $pool = WorkerPool::withThreads(1)->actor('sink', WorkerPoolTestActor::class);
        $node = $this->makeNode();
        $node->start();

        $this->runStepsOn($pool, $node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/sink'));
    }

    #[Test]
    public function behaviorStepSpawnsLocalActorOnNode(): void
    {
        $pool = WorkerPool::withThreads(1)->behavior(
            'pings',
            static fn(): Behavior => Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            ),
        );
        $node = $this->makeNode();
        $node->start();

        $this->runStepsOn($pool, $node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/pings'));
    }

    #[Test]
    public function configureStepReceivesNodeDirectly(): void
    {
        $received = [];
        $pool     = WorkerPool::withThreads(1)->configure(
            static function (WorkerNode $n) use (&$received): void {
                $received[] = $n;
            },
        );
        $node = $this->makeNode();
        $node->start();

        $this->runStepsOn($pool, $node);

        self::assertSame([$node], $received);
    }

    #[Test]
    public function multipleStepsAllExecute(): void
    {
        $pool = WorkerPool::withThreads(1)
            ->actor('first', WorkerPoolTestActor::class)
            ->actor('second', WorkerPoolTestActor::class);
        $node = $this->makeNode();
        $node->start();

        $this->runStepsOn($pool, $node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/first'));
        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/second'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeNode(): WorkerNode
    {
        $runtime   = new TestRuntime();
        $system    = ActorSystem::create('test', $runtime);
        $transport = new InMemoryWorkerTransport();
        $directory = new InMemoryWorkerDirectory();
        $ring      = new ConsistentHashRing(1);

        return new WorkerNode(0, $system, $transport, $ring, $directory);
    }

    /**
     * Extract the private $steps array via reflection and execute each step.
     * This tests the compiled step closures without needing real Swoole threads.
     */
    private function runStepsOn(WorkerPool $pool, WorkerNode $node): void
    {
        $ref      = new ReflectionClass($pool);
        $property = $ref->getProperty('steps');

        /** @var list<Closure(WorkerNode): void> $steps */
        $steps = $property->getValue($pool);

        foreach ($steps as $step) {
            $step($node);
        }
    }
}

final class WorkerPoolTestActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

final class WorkerPoolTestStatefulActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}
