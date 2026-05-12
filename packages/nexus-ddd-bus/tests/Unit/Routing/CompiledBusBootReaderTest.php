<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootReader;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootWriter;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(CompiledBusBootReader::class)]
final class CompiledBusBootReaderTest extends TestCase
{
    private string $snapshotPath;

    #[Test]
    public function readFromRoundTripsWrittenSnapshot(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: CompiledBusBootReaderFixtureHandler::class,
            attributes: [],
            authorizeBeforeValidate: true,
            idempotencyOptedOut: false,
        );
        $original = new BusBuildResult(
            new HandlerAttributeIndex([CompiledBusBootReaderFixtureMessage::class => $entry]),
            [CompiledBusBootReaderFixtureMessage::class => CompiledBusBootReaderFixtureHandler::class],
            [],
        );
        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $original);

        $loaded = new CompiledBusBootReader()->readFrom($this->snapshotPath);

        self::assertSame($original->handlerMap, $loaded->handlerMap);
        $loadedEntry = $loaded->index->lookup(CompiledBusBootReaderFixtureMessage::class)->getUnsafe();
        self::assertInstanceOf(ResolvedAttributesEntry::class, $loadedEntry);
        self::assertSame($entry->handlerClass, $loadedEntry->handlerClass);
        self::assertTrue($loadedEntry->authorizeBeforeValidate);
    }

    #[Test]
    public function readFromAppliesAdopterSuppliedDefaults(): void
    {
        $original = new BusBuildResult(new HandlerAttributeIndex([]), [], []);
        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $original);

        $loaded = new CompiledBusBootReader()->readFrom(
            $this->snapshotPath,
            [],
            causationDepthCap: 8,
            retryBudgetMs: 100,
        );

        self::assertSame(8, $loaded->causationDepthCap);
        self::assertSame(100, $loaded->retryBudgetMs);
    }

    #[Test]
    public function readFromUsesDefaultsWhenNotOverridden(): void
    {
        $original = new BusBuildResult(new HandlerAttributeIndex([]), [], []);
        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $original);

        $loaded = new CompiledBusBootReader()->readFrom($this->snapshotPath);

        self::assertSame(32, $loaded->causationDepthCap);
        self::assertSame(5_000, $loaded->retryBudgetMs);
    }

    #[Test]
    public function readFromMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compiled snapshot not found');

        new CompiledBusBootReader()->readFrom('/tmp/does-not-exist-' . __METHOD__ . '.php');
    }

    #[Test]
    public function readFromNonSnapshotThrows(): void
    {
        file_put_contents($this->snapshotPath, "<?php\nreturn 'not a snapshot';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a CompiledBusBootSnapshot');

        new CompiledBusBootReader()->readFrom($this->snapshotPath);
    }

    #[Test]
    public function readFromPreservesSnapshotShape(): void
    {
        $original = new BusBuildResult(new HandlerAttributeIndex([]), [], []);
        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $original);

        /** @var mixed $snapshot */
        $snapshot = require $this->snapshotPath;
        self::assertInstanceOf(CompiledBusBootSnapshot::class, $snapshot);
        self::assertNotSame('', $snapshot->sourceHash);
    }

    #[Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddd-routes-reader-');
        self::assertNotFalse($path);
        $this->snapshotPath = $path;
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }
}

final readonly class CompiledBusBootReaderFixtureMessage {}

final class CompiledBusBootReaderFixtureHandler {}
