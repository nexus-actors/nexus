<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MessengerActorRef::class)]
final class MessengerActorRefTest extends TestCase
{
    #[Test]
    public function tellWrapsTheMessageInAnEnvelopeAndSends(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out');
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        self::assertNull($sender->sent[0]->last(SourceActorPathStamp::class));
    }

    #[Test]
    public function tellStampsTheSourcePathWhenConfigured(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out', ActorPath::fromString('/user/emitter'));

        $ref->tell(new stdClass());

        $stamp = $sender->sent[0]->last(SourceActorPathStamp::class);

        self::assertInstanceOf(SourceActorPathStamp::class, $stamp);
        self::assertSame('/user/emitter', $stamp->path);
    }

    #[Test]
    public function askThrowsUnsupportedOperation(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        $this->expectException(UnsupportedOperationException::class);

        $ref->ask(new stdClass(), Duration::seconds(1));
    }

    #[Test]
    public function pathIsSyntheticAndRefIsAlwaysAlive(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        self::assertSame('/messenger/orders-out', (string) $ref->path());
        self::assertTrue($ref->isAlive());
    }
}
