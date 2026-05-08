<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingCommandBus::class)]
final class RecordingCommandBusTest extends TestCase
{
    #[Test]
    public function recordsDispatchedCommandsInOrder(): void
    {
        $bus = new RecordingCommandBus();
        $first = new class () implements Command {};
        $second = new class () implements Command {};

        $bus->dispatchCommand($first);
        $bus->dispatchCommand($second);

        self::assertSame([$first, $second], $bus->recorded());
    }

    #[Test]
    public function startsEmpty(): void
    {
        $bus = new RecordingCommandBus();

        self::assertSame([], $bus->recorded());
    }
}
