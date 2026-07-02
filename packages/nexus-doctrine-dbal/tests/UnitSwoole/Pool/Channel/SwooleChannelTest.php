<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\UnitSwoole\Pool\Channel;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleChannel::class)]
#[RequiresPhpExtension('swoole')]
final class SwooleChannelTest extends TestCase
{
    #[Test]
    public function pushPopRoundTrip(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        run(static function (): void {
            $channel = new SwooleChannel(capacity: 4);
            $item = new stdClass();

            self::assertTrue($channel->push($item));
            self::assertSame($item, $channel->pop(Duration::seconds(1)));
        });
    }

    #[Test]
    public function popSuspendsUntilPushFromAnotherCoroutine(): void
    {
        $received = null;
        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$received): void {
            $channel = new SwooleChannel(capacity: 4);
            $item = new stdClass();

            Coroutine::create(static function () use ($channel, $item): void {
                Coroutine::sleep(0.01);
                $channel->push($item);
            });

            $received = $channel->pop(Duration::seconds(1));
        });

        self::assertNotNull($received);
    }

    #[Test]
    public function popReturnsNullOnTimeout(): void
    {
        $result = 'unset';
        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$result): void {
            $channel = new SwooleChannel(capacity: 4);
            $result = $channel->pop(Duration::nanos(1_000_000));
        });

        self::assertNull($result);
    }

    #[Test]
    public function popWithZeroDurationReturnsNullImmediately(): void
    {
        $result = 'unset';
        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$result): void {
            $channel = new SwooleChannel(capacity: 4);
            $result = $channel->pop(Duration::zero());
        });

        self::assertNull($result);
    }
}
