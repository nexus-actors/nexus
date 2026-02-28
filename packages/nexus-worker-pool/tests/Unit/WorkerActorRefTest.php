<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Tests\Unit\Support\Ping;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WorkerActorRef::class)]
final class WorkerActorRefTest extends TestCase
{
    #[Test]
    public function tellSendsEnvelopeToTransport(): void
    {
        $transport = new InMemoryWorkerTransport();
        $directory = new InMemoryWorkerDirectory();
        $path = ActorPath::fromString('/user/orders');
        $directory->register((string) $path, 2);

        $ref = new WorkerActorRef(
            path: $path,
            targetWorker: 2,
            transport: $transport,
            directory: $directory,
            askHandler: static fn() => throw new RuntimeException('not used'),
        );

        $ref->tell(new Ping());

        $sent = $transport->getSentTo(2);
        self::assertCount(1, $sent);
        self::assertInstanceOf(Ping::class, $sent[0]->message);
    }

    #[Test]
    public function isAliveWhenInDirectory(): void
    {
        $transport = new InMemoryWorkerTransport();
        $directory = new InMemoryWorkerDirectory();
        $path = ActorPath::fromString('/user/orders');
        $directory->register((string) $path, 2);

        $ref = new WorkerActorRef(
            path: $path,
            targetWorker: 2,
            transport: $transport,
            directory: $directory,
            askHandler: static fn() => throw new RuntimeException('not used'),
        );

        self::assertTrue($ref->isAlive());
    }
}
