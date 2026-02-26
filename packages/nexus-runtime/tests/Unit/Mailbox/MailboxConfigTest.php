<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit\Mailbox;

use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailboxConfig::class)]
final class MailboxConfigTest extends TestCase
{
    #[Test]
    public function boundedCreatesBoundedConfig(): void
    {
        $config = MailboxConfig::bounded(100);

        self::assertSame(100, $config->capacity);
        self::assertSame(OverflowStrategy::ThrowException, $config->strategy);
        self::assertTrue($config->bounded);
    }
}
