<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\DirectRemoteActorRef;
use Monadial\Nexus\Cluster\Tests\Unit\Support\Ping;
use Monadial\Nexus\Cluster\Transport\InMemoryEnvelopeTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DirectRemoteActorRef::class)]
final class DirectRemoteActorRefTest extends TestCase
{
    #[Test]
    public function pathReturnsActorPath(): void
    {
        $ref = $this->createRef('/user/orders', 3);

        self::assertSame('/user/orders', (string) $ref->path());
    }

    #[Test]
    public function tellSendsEnvelopeViaTransport(): void
    {
        $transport = new InMemoryEnvelopeTransport();
        $ref = $this->createRefWithTransport('/user/orders', 3, $transport);

        $ref->tell(new Ping('hello'));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(3, $sent[0]['targetWorker']);
        self::assertInstanceOf(Ping::class, $sent[0]['envelope']->message);
        self::assertSame('hello', $sent[0]['envelope']->message->payload);
        self::assertSame('/user/orders', (string) $sent[0]['envelope']->target);
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

        /** @psalm-suppress UnrecognizedExpression */
        (void) $ref->ask(static fn() => new Ping(), Duration::seconds(1));
    }

    /** @return DirectRemoteActorRef<object> */
    private function createRef(string $path, int $targetWorker): DirectRemoteActorRef
    {
        return new DirectRemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            new InMemoryEnvelopeTransport(),
            new InMemoryDirectory(),
        );
    }

    /** @return DirectRemoteActorRef<object> */
    private function createRefWithTransport(
        string $path,
        int $targetWorker,
        InMemoryEnvelopeTransport $transport,
    ): DirectRemoteActorRef {
        return new DirectRemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            $transport,
            new InMemoryDirectory(),
        );
    }

    /** @return DirectRemoteActorRef<object> */
    private function createRefWithDirectory(
        string $path,
        int $targetWorker,
        InMemoryDirectory $directory,
    ): DirectRemoteActorRef {
        return new DirectRemoteActorRef(
            ActorPath::fromString($path),
            $targetWorker,
            new InMemoryEnvelopeTransport(),
            $directory,
        );
    }
}
