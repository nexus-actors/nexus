<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Ask;

use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapReplySenderLocator::class)]
final class MapReplySenderLocatorTest extends TestCase
{
    #[Test]
    public function returnsSenderForKnownChannelName(): void
    {
        $sender = new RecordingSender();
        $locator = new MapReplySenderLocator(['replies' => $sender]);

        self::assertSame($sender, $locator->senderFor('replies'));
    }

    #[Test]
    public function returnsNullForUnknownChannelName(): void
    {
        $locator = new MapReplySenderLocator([]);

        self::assertNull($locator->senderFor('unknown'));
    }
}
