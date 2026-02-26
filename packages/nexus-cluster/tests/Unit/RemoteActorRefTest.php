<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Tests\Unit\Support\Ping;
use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Tests\Support\TestFutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function askDelegatesToHandler(): void
    {
        $slot = new TestFutureSlot();
        $slot->resolve(new Ping('reply'));
        $capturedPath = null;
        $capturedWorker = null;
        $capturedMessage = null;
        $capturedTimeout = null;

        $ref = $this->createRef(
            '/user/orders',
            3,
            static function (ActorPath $path, int $targetWorker, object $message, Duration $timeout) use (
                &$capturedPath,
                &$capturedWorker,
                &$capturedMessage,
                &$capturedTimeout,
                $slot,
            ): Future {
                $capturedPath = $path;
                $capturedWorker = $targetWorker;
                $capturedMessage = $message;
                $capturedTimeout = $timeout;

                return new Future($slot);
            },
        );

        $reply = $ref->ask(new Ping('request'), Duration::seconds(1))->await();

        self::assertSame('/user/orders', (string) $capturedPath);
        self::assertSame(3, $capturedWorker);
        self::assertInstanceOf(Ping::class, $capturedMessage);
        self::assertSame('request', $capturedMessage->payload);
        self::assertSame('1s', (string) $capturedTimeout);
        self::assertInstanceOf(Ping::class, $reply);
        self::assertSame('reply', $reply->payload);
    }

    /** @return RemoteActorRef<object> */
    private function createRef(string $path, int $targetWorker, ?callable $askHandler = null,): RemoteActorRef
    {
        return new RemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            new InMemoryTransport(),
            new PhpNativeClusterSerializer(),
            new InMemoryDirectory(),
            $askHandler ?? static fn(ActorPath $path, int $targetWorker, object $message, Duration $timeout): Future => new Future(
                new TestFutureSlot(),
            ),
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
            static fn(ActorPath $path, int $targetWorker, object $message, Duration $timeout): Future => new Future(
                new TestFutureSlot(),
            ),
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
            static fn(ActorPath $path, int $targetWorker, object $message, Duration $timeout): Future => new Future(
                new TestFutureSlot(),
            ),
        );
    }
}
