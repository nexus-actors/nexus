<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledBusBootSnapshot::class)]
final class CompiledBusBootSnapshotTest extends TestCase
{
    #[Test]
    public function constructsWithSourceHashHandlerMapAndEntries(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: CompiledBusBootSnapshotFixtureHandler::class,
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );

        $snapshot = new CompiledBusBootSnapshot(
            sourceHash: 'deadbeef',
            handlerMap: [CompiledBusBootSnapshotFixtureMessage::class => CompiledBusBootSnapshotFixtureHandler::class],
            entries: [CompiledBusBootSnapshotFixtureMessage::class => $entry],
        );

        self::assertSame('deadbeef', $snapshot->sourceHash);
        self::assertSame(
            [CompiledBusBootSnapshotFixtureMessage::class => CompiledBusBootSnapshotFixtureHandler::class],
            $snapshot->handlerMap,
        );
        self::assertSame([CompiledBusBootSnapshotFixtureMessage::class => $entry], $snapshot->entries);
    }
}

final readonly class CompiledBusBootSnapshotFixtureMessage {}

final class CompiledBusBootSnapshotFixtureHandler {}
