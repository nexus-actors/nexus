<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Inbox;

use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\InMemoryMessageInbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryMessageInbox::class)]
final class InMemoryMessageInboxContentionTest extends TestCase
{
    #[Test]
    public function tryReserveIsTransitivelyIdempotentAcrossTenSequentialAttempts(): void
    {
        $inbox = new InMemoryMessageInbox();
        $id = MessageId::generate();

        $successCount = 0;

        for ($i = 0; $i < 10; $i++) {
            if ($inbox->tryReserve(self::class, $id)) {
                $successCount++;
            }
        }

        self::assertSame(1, $successCount);
    }
}
