<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Server\Swoole\Threads\Actor\WorkerNodePoolSingletonSpawner;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerNodePoolSingletonSpawner::class)]
final class WorkerNodePoolSingletonSpawnerTest extends TestCase
{
    #[Test]
    public function spawn_delegates_to_worker_node_and_returns_alive_ref(): void
    {
        $node = $this->createSingleWorkerNode();
        $spawner = new WorkerNodePoolSingletonSpawner($node);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $ref = $spawner->spawn($props, 'singleton');

        self::assertTrue($ref->isAlive());
        self::assertSame('/user/singleton', (string) $ref->path());
    }

    #[Test]
    public function spawn_uses_the_provided_actor_name_for_path(): void
    {
        $node = $this->createSingleWorkerNode();
        $spawner = new WorkerNodePoolSingletonSpawner($node);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $ref = $spawner->spawn($props, 'orders-singleton');

        self::assertSame('/user/orders-singleton', (string) $ref->path());
    }

    private function createSingleWorkerNode(): WorkerNode
    {
        $runtime = new TestRuntime(new TestClock());
        $system = ActorSystem::create('worker-0', $runtime);

        return new WorkerNode(
            0,
            $system,
            new InMemoryWorkerTransport(),
            new ConsistentHashRing(1),
            new InMemoryWorkerDirectory(),
        );
    }
}
