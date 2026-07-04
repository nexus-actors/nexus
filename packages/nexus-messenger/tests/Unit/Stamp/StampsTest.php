<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Stamp;

use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\ReplyToStamp;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(CorrelationIdStamp::class)]
#[CoversClass(ReplyToStamp::class)]
#[CoversClass(SourceActorPathStamp::class)]
#[CoversClass(TargetActorPathStamp::class)]
final class StampsTest extends TestCase
{
    #[Test]
    public function correlationIdStampCarriesIdAndIsAMessengerStamp(): void
    {
        $stamp = new CorrelationIdStamp('req-12345');

        self::assertSame('req-12345', $stamp->id);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    #[Test]
    public function replyToStampCarriesChannelAndIsAMessengerStamp(): void
    {
        $stamp = new ReplyToStamp('reply-queue');

        self::assertSame('reply-queue', $stamp->channel);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    #[Test]
    public function sourceStampCarriesPathAndIsAMessengerStamp(): void
    {
        $stamp = new SourceActorPathStamp('/user/emitter');

        self::assertSame('/user/emitter', $stamp->path);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    #[Test]
    public function targetStampCarriesPathAndIsAMessengerStamp(): void
    {
        $stamp = new TargetActorPathStamp('/user/orders');

        self::assertSame('/user/orders', $stamp->path);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }
}
