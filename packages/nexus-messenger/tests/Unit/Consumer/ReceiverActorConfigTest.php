<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Consumer;

use InvalidArgumentException;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReceiverActorConfig::class)]
final class ReceiverActorConfigTest extends TestCase
{
    #[Test]
    public function defaultsTo100msPollAndReject(): void
    {
        $config = ReceiverActorConfig::default();

        self::assertTrue($config->pollInterval->equals(Duration::millis(100)));
        self::assertSame(UnroutablePolicy::Reject, $config->unroutablePolicy);
    }

    #[Test]
    public function withersReturnModifiedCopies(): void
    {
        $config = ReceiverActorConfig::default()
            ->withPollInterval(Duration::millis(20))
            ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);

        self::assertTrue($config->pollInterval->equals(Duration::millis(20)));
        self::assertSame(UnroutablePolicy::DeadLetters, $config->unroutablePolicy);
        self::assertSame(UnroutablePolicy::Reject, ReceiverActorConfig::default()->unroutablePolicy);
    }

    #[Test]
    public function withAskPendingTimeoutReturnsCopyWithUpdatedTimeout(): void
    {
        $config = ReceiverActorConfig::default()->withAskPendingTimeout(Duration::seconds(5));

        self::assertTrue($config->askPendingTimeout->equals(Duration::seconds(5)));
        self::assertTrue(ReceiverActorConfig::default()->askPendingTimeout->equals(Duration::seconds(30)));
        // Other fields are unchanged.
        self::assertTrue($config->pollInterval->equals(Duration::millis(100)));
        self::assertSame(UnroutablePolicy::Reject, $config->unroutablePolicy);
    }

    #[Test]
    public function defaultsToBoundedPendingAsks(): void
    {
        self::assertSame(1024, ReceiverActorConfig::default()->maxPendingAsks);
    }

    #[Test]
    public function withMaxPendingAsksReturnsCopyWithUpdatedCap(): void
    {
        $config = ReceiverActorConfig::default()->withMaxPendingAsks(50);

        self::assertSame(50, $config->maxPendingAsks);
        self::assertSame(1024, ReceiverActorConfig::default()->maxPendingAsks);
        // Other fields are unchanged.
        self::assertTrue($config->pollInterval->equals(Duration::millis(100)));
        self::assertSame(UnroutablePolicy::Reject, $config->unroutablePolicy);
    }

    #[Test]
    public function rejectsNonPositiveMaxPendingAsks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiverActorConfig::default()->withMaxPendingAsks(0);
    }
}
