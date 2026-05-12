<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootWriter;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(CompiledBusBootWriter::class)]
final class CompiledBusBootWriterTest extends TestCase
{
    private string $snapshotPath;

    #[Test]
    public function writeToProducesParsableSnapshot(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: CompiledBusBootWriterFixtureHandler::class,
            attributes: [Validate::class => new Validate(groups: ['order'])],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );
        $result = new BusBuildResult(
            new HandlerAttributeIndex([CompiledBusBootWriterFixtureMessage::class => $entry]),
            [CompiledBusBootWriterFixtureMessage::class => CompiledBusBootWriterFixtureHandler::class],
            [],
        );

        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $result);

        $contents = file_get_contents($this->snapshotPath);
        self::assertIsString($contents);
        self::assertStringStartsWith('<?php', $contents);
        self::assertStringContainsString('CompiledBusBootSnapshot', $contents);
        self::assertStringContainsString('ResolvedAttributesEntry', $contents);
        self::assertStringContainsString('sourceHash:', $contents);
    }

    #[Test]
    public function emittedSnapshotCarriesNonEmptySourceHash(): void
    {
        $result = new BusBuildResult(
            new HandlerAttributeIndex([]),
            [],
            [],
        );

        new CompiledBusBootWriter()->writeTo($this->snapshotPath, $result);

        /** @var mixed $snapshot */
        $snapshot = require $this->snapshotPath;
        self::assertInstanceOf(CompiledBusBootSnapshot::class, $snapshot);
        self::assertNotSame('', $snapshot->sourceHash);
    }

    #[Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddd-routes-writer-');
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

final readonly class CompiledBusBootWriterFixtureMessage {}

final class CompiledBusBootWriterFixtureHandler {}
