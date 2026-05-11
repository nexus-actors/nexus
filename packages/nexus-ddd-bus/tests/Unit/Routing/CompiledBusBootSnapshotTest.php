<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledHandlerEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledBusBootSnapshot::class)]
final class CompiledBusBootSnapshotTest extends TestCase
{
    #[Test]
    public function constructsWithHandlerMapAndEntries(): void
    {
        $entry = new CompiledHandlerEntry(
            handlerClass: CompiledBusBootSnapshotFixtureHandler::class,
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );

        $snapshot = new CompiledBusBootSnapshot(
            handlerMap: [CompiledBusBootSnapshotFixtureMessage::class => CompiledBusBootSnapshotFixtureHandler::class],
            entries: [CompiledBusBootSnapshotFixtureMessage::class => $entry],
        );

        self::assertSame(
            [CompiledBusBootSnapshotFixtureMessage::class => CompiledBusBootSnapshotFixtureHandler::class],
            $snapshot->handlerMap,
        );
        self::assertSame([CompiledBusBootSnapshotFixtureMessage::class => $entry], $snapshot->entries);
    }
}

final readonly class CompiledBusBootSnapshotFixtureMessage {}

final class CompiledBusBootSnapshotFixtureHandler {}
