<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Remote;

use Monadial\Nexus\Cluster\Protocol\RemoteAskReply;
use Monadial\Nexus\Cluster\Remote\RemoteReplyRef;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Tests\Unit\Support\Ping;
use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteReplyRef::class)]
final class RemoteReplyRefTest extends TestCase
{
    #[Test]
    public function tellSendsRemoteAskReplyWhenCallbackAllows(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();

        $ref = new RemoteReplyRef(
            requestId: 'req-1',
            replyToWorker: 2,
            path: ActorPath::fromString('/temp/remote-ask-req-1'),
            transport: $transport,
            serializer: $serializer,
            onReply: static fn(object $reply): bool => true,
        );

        $ref->tell(new Ping('ok'));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(2, $sent[0]['targetWorker']);

        $envelope = $serializer->deserialize($sent[0]['data']);
        self::assertInstanceOf(RemoteAskReply::class, $envelope->message);
        self::assertSame('req-1', $envelope->message->requestId);
        self::assertInstanceOf(Ping::class, $envelope->message->payload);
    }

    #[Test]
    public function tellDoesNotSendWhenCallbackRejects(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();

        $ref = new RemoteReplyRef(
            requestId: 'req-2',
            replyToWorker: 2,
            path: ActorPath::fromString('/temp/remote-ask-req-2'),
            transport: $transport,
            serializer: $serializer,
            onReply: static fn(object $reply): bool => false,
        );

        $ref->tell(new Ping('late'));

        self::assertCount(0, $transport->getSent());
    }
}
