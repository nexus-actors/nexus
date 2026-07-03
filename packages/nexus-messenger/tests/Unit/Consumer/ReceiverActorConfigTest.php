<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Consumer;

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
}
