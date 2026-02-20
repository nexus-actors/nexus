<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Tests\Unit\Support\Ping;
use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RemoteActorRef::class)]
final class RemoteActorRefTest extends TestCase
{
    #[Test]
    public function pathReturnsActorPath(): void
    {
        $ref = $this->createRef('/user/orders', 3);

        self::assertSame('/user/orders', (string) $ref->path());
    }

    #[Test]
    public function tellSerializesAndSendsViaTransport(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();
        $ref = $this->createRefWith('/user/orders', 3, $transport, $serializer);

        $ref->tell(new Ping('hello'));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(3, $sent[0]['targetWorker']);

        // Verify the sent data is a valid serialized envelope
        $envelope = $serializer->deserialize($sent[0]['data']);
        self::assertInstanceOf(Ping::class, $envelope->message);
        self::assertSame('hello', $envelope->message->payload);
        self::assertSame('/user/orders', (string) $envelope->target);
    }

    #[Test]
    public function isAliveChecksDirectory(): void
    {
        $directory = new InMemoryDirectory();
        $directory->register('/user/orders', 3);
        $ref = $this->createRefWithDirectory('/user/orders', 3, $directory);

        self::assertTrue($ref->isAlive());

        $directory->remove('/user/orders');

        self::assertFalse($ref->isAlive());
    }

    #[Test]
    public function askThrowsRuntimeException(): void
    {
        $ref = $this->createRef('/user/orders', 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported for remote actors');

        (void) $ref->ask(static fn() => new Ping(), Duration::seconds(1));
    }

    /** @return RemoteActorRef<object> */
    private function createRef(string $path, int $targetWorker): RemoteActorRef
    {
        return new RemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            new InMemoryTransport(),
            new PhpNativeClusterSerializer(),
            new InMemoryDirectory(),
        );
    }

    /** @return RemoteActorRef<object> */
    private function createRefWith(
        string $path,
        int $targetWorker,
        InMemoryTransport $transport,
        PhpNativeClusterSerializer $serializer,
    ): RemoteActorRef {
        return new RemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            $transport,
            $serializer,
            new InMemoryDirectory(),
        );
    }

    /** @return RemoteActorRef<object> */
    private function createRefWithDirectory(
        string $path,
        int $targetWorker,
        InMemoryDirectory $directory,
    ): RemoteActorRef {
        return new RemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            new InMemoryTransport(),
            new PhpNativeClusterSerializer(),
            $directory,
        );
    }
}
