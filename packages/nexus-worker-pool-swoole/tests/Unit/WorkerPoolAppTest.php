<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolApp::class)]
final class WorkerPoolAppTest extends TestCase
{
    #[Test]
    public function configureIsCalledWithNode(): void
    {
        $runtime = new TestRuntime();
        $system = ActorSystem::create('test', $runtime);
        $node = new WorkerNode(
            workerId: 0,
            system: $system,
            transport: new InMemoryWorkerTransport(),
            ring: new ConsistentHashRing(1),
            directory: new InMemoryWorkerDirectory(),
        );

        $spawned = [];

        $app = new class ($spawned) extends WorkerPoolApp {
            /**
             * @param list<string> $names
             */
            public function __construct(private array &$names) {}

            protected function configure(WorkerNode $node): void
            {
                $this->names[] = 'configured';
            }
        };

        $app->onWorkerStart($node);

        self::assertSame(['configured'], $spawned);
    }
}
