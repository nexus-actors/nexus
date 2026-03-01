<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPool;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function Opis\Closure\serialize as opis_serialize;
use function Opis\Closure\unserialize as opis_unserialize;

#[CoversClass(WorkerPool::class)]
final class WorkerPoolDslTest extends TestCase
{
    #[Test]
    public function actorStepSurvivesSerializeDeserializeRoundTrip(): void
    {
        $pool = WorkerPool::withThreads(1)->actor('sink', DslIntegrationActor::class);

        $configure = $this->extractAndSerializeSteps($pool);
        $node = $this->makeNode();

        $configure($node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/sink'));
    }

    #[Test]
    public function behaviorStepSurvivesSerializeDeserializeRoundTrip(): void
    {
        $pool = WorkerPool::withThreads(1)->behavior(
            'pings',
            static fn(): Behavior => Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            ),
        );

        $configure = $this->extractAndSerializeSteps($pool);
        $node = $this->makeNode();

        $configure($node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/pings'));
    }

    #[Test]
    public function configureStepSurvivesSerializeDeserializeRoundTrip(): void
    {
        $pool = WorkerPool::withThreads(1)->configure(
            static function (WorkerNode $node): void {
                $node->spawn(
                    Props::fromFactory(static fn(): ActorHandler => new DslIntegrationActor()),
                    'custom',
                );
            },
        );

        $configure = $this->extractAndSerializeSteps($pool);
        $node = $this->makeNode();

        $configure($node);

        self::assertInstanceOf(LocalActorRef::class, $node->actorFor('/user/custom'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract the steps from WorkerPool, combine into one closure, serialize
     * with opis/closure, deserialize, and return the callable.
     *
     * @return callable(WorkerNode): void
     */
    private function extractAndSerializeSteps(WorkerPool $pool): callable
    {
        $ref = new ReflectionClass($pool);
        $property = $ref->getProperty('steps');

        /** @var list<Closure(WorkerNode): void> $steps */
        $steps = $property->getValue($pool);
        $combined = static function (WorkerNode $node) use ($steps): void {
            foreach ($steps as $step) {
                $step($node);
            }
        };

        $serialized = opis_serialize($combined);

        return opis_unserialize($serialized);
    }

    private function makeNode(): WorkerNode
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime);
        $transport = new InMemoryWorkerTransport();
        $directory = new InMemoryWorkerDirectory();
        $ring = new ConsistentHashRing(1);

        return new WorkerNode(0, $system, $transport, $ring, $directory);
    }
}

final class DslIntegrationActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}
