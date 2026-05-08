<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingEventBus::class)]
final class RecordingEventBusTest extends TestCase
{
    #[Test]
    public function recordsPublishedEventsInOrder(): void
    {
        $bus = new RecordingEventBus();
        $first = new class () implements DomainEvent {};
        $second = new class () implements DomainEvent {};

        $bus->publishEvent($first);
        $bus->publishEvent($second);

        self::assertSame([$first, $second], $bus->recorded());
    }

    #[Test]
    public function startsEmpty(): void
    {
        $bus = new RecordingEventBus();

        self::assertSame([], $bus->recorded());
    }
}
