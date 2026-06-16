<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool\Channel;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(FiberChannel::class)]
final class FiberChannelTest extends TestCase
{
    #[Test]
    public function pushThenPopReturnsSameItem(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $item = new stdClass();

        self::assertTrue($channel->push($item));
        self::assertSame($item, $channel->pop(Duration::zero()));
    }

    #[Test]
    public function popOnEmptyReturnsNullImmediately(): void
    {
        $channel = new FiberChannel(capacity: 4);

        self::assertNull($channel->pop(Duration::seconds(1)));
    }

    #[Test]
    public function pushAtCapacityReturnsFalse(): void
    {
        $channel = new FiberChannel(capacity: 1);

        self::assertTrue($channel->push(new stdClass()));
        self::assertFalse($channel->push(new stdClass()));
    }

    #[Test]
    public function fifoOrdering(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $a = new stdClass();
        $b = new stdClass();
        $channel->push($a);
        $channel->push($b);

        self::assertSame($a, $channel->pop(Duration::zero()));
        self::assertSame($b, $channel->pop(Duration::zero()));
    }

    #[Test]
    public function closeDrainsPushers(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $channel->push(new stdClass());

        $channel->close();

        self::assertTrue($channel->isClosed());
        self::assertNull($channel->pop(Duration::zero()));
        self::assertFalse($channel->push(new stdClass()));
    }
}
