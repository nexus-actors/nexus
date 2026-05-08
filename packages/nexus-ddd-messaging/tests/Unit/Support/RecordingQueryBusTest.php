<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingQueryBus::class)]
final class RecordingQueryBusTest extends TestCase
{
    #[Test]
    public function returnsCannedResponseAndRecordsQuery(): void
    {
        $bus = new RecordingQueryBus();
        $query = new class () implements Query {};
        $bus->respondWith($query::class, 'result-value');

        $result = $bus->dispatchQuery($query);

        self::assertSame('result-value', $result);
        self::assertSame([$query], $bus->recorded());
    }

    #[Test]
    public function throwsHandlerNotFoundExceptionWhenNoCannedResponse(): void
    {
        $bus = new RecordingQueryBus();
        $query = new class () implements Query {};

        $this->expectException(HandlerNotFoundException::class);
        $bus->dispatchQuery($query);
    }

    #[Test]
    public function startsEmpty(): void
    {
        $bus = new RecordingQueryBus();

        self::assertSame([], $bus->recorded());
    }
}
