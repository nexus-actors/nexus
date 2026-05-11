<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(BusBuilder::class)]
final class BusBuilderCompileCacheTest extends TestCase
{
    private string $snapshotPath;

    #[Test]
    public function dumpCompiledToWritesParsablePhpReturningSnapshot(): void
    {
        new BusBuilder()
            ->registerHandler(CompileCacheMessage::class, CompileCacheHandler::class)
            ->dumpCompiledTo(
                $this->snapshotPath,
                Profile::Sync,
                hasValidator: true,
                hasDecider: true,
                routing: new Composite([], 'misc'),
            );

        self::assertFileExists($this->snapshotPath);
        $contents = file_get_contents($this->snapshotPath);
        self::assertIsString($contents);
        self::assertStringStartsWith('<?php', $contents);
        self::assertStringContainsString('CompiledBusBootSnapshot', $contents);

        /** @var mixed $snapshot */
        $snapshot = require $this->snapshotPath;
        self::assertInstanceOf(CompiledBusBootSnapshot::class, $snapshot);
        self::assertSame([CompileCacheMessage::class => CompileCacheHandler::class], $snapshot->handlerMap);
    }

    #[Test]
    public function loadCompiledFromProducesEquivalentBuildResult(): void
    {
        $builder = new BusBuilder()
            ->registerHandler(CompileCacheMessage::class, CompileCacheHandler::class);

        $expected = $builder->build(
            Profile::Sync,
            hasValidator: true,
            hasDecider: true,
            routing: new Composite([], 'misc'),
        );

        $builder->dumpCompiledTo(
            $this->snapshotPath,
            Profile::Sync,
            hasValidator: true,
            hasDecider: true,
            routing: new Composite([], 'misc'),
        );

        $loaded = $builder->loadCompiledFrom($this->snapshotPath);

        self::assertSame($expected->handlerMap, $loaded->handlerMap);

        $expectedEntry = $expected->index->lookup(CompileCacheMessage::class)->getUnsafe();
        $loadedEntry = $loaded->index->lookup(CompileCacheMessage::class)->getUnsafe();

        self::assertInstanceOf(ResolvedAttributesEntry::class, $loadedEntry);
        self::assertSame($expectedEntry->handlerClass, $loadedEntry->handlerClass);
        self::assertSame($expectedEntry->authorizeBeforeValidate, $loadedEntry->authorizeBeforeValidate);
        self::assertSame($expectedEntry->idempotencyOptedOut, $loadedEntry->idempotencyOptedOut);

        self::assertEquals($expectedEntry->attributes[Validate::class], $loadedEntry->attributes[Validate::class]);
        self::assertEquals($expectedEntry->attributes[Authorize::class], $loadedEntry->attributes[Authorize::class]);
        self::assertEquals($expectedEntry->attributes[Idempotent::class], $loadedEntry->attributes[Idempotent::class]);
    }

    #[Test]
    public function dumpAndLoadRoundTripsNullableAttributeFields(): void
    {
        new BusBuilder()
            ->registerHandler(CompileCacheMessage::class, CompileCacheHandlerAuthorizeOnly::class)
            ->dumpCompiledTo(
                $this->snapshotPath,
                Profile::Sync,
                hasValidator: false,
                hasDecider: true,
                routing: new Composite([], 'misc'),
            );

        $loaded = new BusBuilder()->loadCompiledFrom($this->snapshotPath);
        $entry = $loaded->index->lookup(CompileCacheMessage::class)->getUnsafe();

        $authorize = $entry->attributes[Authorize::class];
        self::assertInstanceOf(Authorize::class, $authorize);
        self::assertSame('order.cancel', $authorize->policy);
        self::assertNull($authorize->subject);
        self::assertNull($authorize->before);
    }

    #[Test]
    public function loadCompiledFromMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compiled snapshot not found');

        new BusBuilder()->loadCompiledFrom('/tmp/does-not-exist-' . __METHOD__ . '.php');
    }

    #[Test]
    public function loadCompiledFromFileReturningNonSnapshotThrows(): void
    {
        file_put_contents($this->snapshotPath, "<?php\nreturn 'not a snapshot';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a CompiledBusBootSnapshot');

        new BusBuilder()->loadCompiledFrom($this->snapshotPath);
    }

    #[Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddd-routes-test-');
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

final readonly class CompileCacheMessage {}

#[Idempotent(off: true)]
final class CompileCacheHandler
{
    #[Validate(groups: ['order'])]
    #[Authorize(policy: 'order.place', subject: 'orderId', before: 'validation')]
    public function handle(CompileCacheMessage $message): void
    {
        // fixture: BusBuilder reflects this method to build the snapshot under test.
    }
}

final class CompileCacheHandlerAuthorizeOnly
{
    #[Authorize(policy: 'order.cancel')]
    public function handle(CompileCacheMessage $message): void
    {
        // fixture: Authorize with all-null subject/before — exercises null-field rendering.
    }
}
