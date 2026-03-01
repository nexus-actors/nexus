<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPool;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(WorkerNode::class)]
final class WorkerNodeTest extends TestCase
{
    private ConsistentHashRing $ring;
    private InMemoryWorkerDirectory $directory;

    #[Test]
    public function spawnLocalActorWhenHashPointsToThisWorker(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker0 = $this->findNameForWorker(0);
        $ref = $node->spawn(Props::fromBehavior($behavior), $nameForWorker0);

        self::assertInstanceOf(LocalActorRef::class, $ref);
    }

    #[Test]
    public function spawnRemoteActorWhenHashPointsToOtherWorker(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker1 = $this->findNameForWorker(1);
        $ref = $node->spawn(Props::fromBehavior($behavior), $nameForWorker1);

        self::assertInstanceOf(WorkerActorRef::class, $ref);
    }

    #[Test]
    public function tellViaWorkerActorRefEnqueuesEnvelopeOnTransport(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker1 = $this->findNameForWorker(1);
        $ref = $node->spawn(Props::fromBehavior($behavior), $nameForWorker1);
        self::assertInstanceOf(WorkerActorRef::class, $ref);

        $ref->tell(new stdClass());

        $sent = $transport->getSentTo(1);
        self::assertCount(1, $sent);
        self::assertInstanceOf(Envelope::class, $sent[0]);
    }

    #[Test]
    public function directoryRegistrationDuringSpawn(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker0 = $this->findNameForWorker(0);
        $nameForWorker1 = $this->findNameForWorker(1);

        $node->spawn(Props::fromBehavior($behavior), $nameForWorker0);
        $node->spawn(Props::fromBehavior($behavior), $nameForWorker1);

        self::assertTrue($this->directory->has("/user/{$nameForWorker0}"));
        self::assertTrue($this->directory->has("/user/{$nameForWorker1}"));
        self::assertSame(0, $this->directory->lookup("/user/{$nameForWorker0}"));
        self::assertSame(1, $this->directory->lookup("/user/{$nameForWorker1}"));
    }

    #[Test]
    public function actorForReturnsNullForUnregisteredPath(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);

        self::assertNull($node->actorFor('/user/nonexistent'));
    }

    #[Test]
    public function actorForReturnsLocalRefForOwnedActor(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker0 = $this->findNameForWorker(0);
        $node->spawn(Props::fromBehavior($behavior), $nameForWorker0);

        $ref = $node->actorFor("/user/{$nameForWorker0}");

        self::assertInstanceOf(LocalActorRef::class, $ref);
    }

    #[Test]
    public function actorForReturnsWorkerActorRefForRemoteActor(): void
    {
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);
        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());

        $nameForWorker1 = $this->findNameForWorker(1);
        $node->spawn(Props::fromBehavior($behavior), $nameForWorker1);

        $ref = $node->actorFor("/user/{$nameForWorker1}");

        self::assertInstanceOf(WorkerActorRef::class, $ref);
    }

    #[Test]
    public function incomingEnvelopeDeliveredToLocalActor(): void
    {
        $received = [];
        $transport = new InMemoryWorkerTransport();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('worker-0', $runtime);
        $node = new WorkerNode(0, $system, $transport, $this->ring, $this->directory);

        $nameForWorker0 = $this->findNameForWorker(0);
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                $received[] = $msg;

                return Behavior::same();
            },
        );

        $ref = $node->spawn(Props::fromBehavior($behavior), $nameForWorker0);
        self::assertInstanceOf(LocalActorRef::class, $ref);

        $node->start();

        $message = new stdClass();
        $envelope = Envelope::of($message, $ref->path(), $ref->path());
        $transport->receive($envelope);

        // Run the fiber runtime briefly to deliver the message
        $runtime->scheduleOnce(
            Duration::millis(50),
            static fn() => $system->shutdown(Duration::millis(50)),
        );
        $system->run();

        self::assertCount(1, $received);
        self::assertSame($message, $received[0]);
    }

    protected function setUp(): void
    {
        $this->ring = new ConsistentHashRing(2);
        $this->directory = new InMemoryWorkerDirectory();
    }

    private function findNameForWorker(int $workerId): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $name = "actor-{$i}";

            if ($this->ring->getWorker($name) === $workerId) {
                return $name;
            }
        }

        self::fail("Could not find a name that hashes to worker {$workerId}");
    }
}
