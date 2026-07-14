<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit;

use ArrayObject;
use Monadial\Nexus\Observability\Otel\OtelPsrLogger;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(OtelPsrLogger::class)]
final class OtelPsrLoggerTest extends TestCase
{
    /** @var ArrayObject<int, ReadableLogRecord> */
    private ArrayObject $storage;

    private OtelPsrLogger $logger;

    #[Test]
    public function emitsBodySeverityAndScalarAttributes(): void
    {
        $this->logger->warning('disk almost full', ['mount' => '/data', 'usage' => 92]);

        $record = $this->lastRecord();
        self::assertSame('disk almost full', $record->getBody());
        self::assertSame('warning', $record->getSeverityText());

        $attributes = $record->getAttributes()->toArray();
        self::assertSame('/data', $attributes['mount']);
        self::assertSame(92, $attributes['usage']);
    }

    #[Test]
    public function mapsPsr3LevelToAHigherSeverityNumberForErrors(): void
    {
        $this->logger->info('fyi');
        $infoSeverity = $this->lastRecord()->getSeverityNumber();

        $this->logger->error('boom');
        $errorSeverity = $this->lastRecord()->getSeverityNumber();

        self::assertNotNull($infoSeverity);
        self::assertNotNull($errorSeverity);
        self::assertGreaterThan($infoSeverity, $errorSeverity);
    }

    #[Test]
    public function stringifiesNonScalarContextValues(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'node-7';
            }
        };

        $this->logger->info('joined', ['node' => $stringable, 'meta' => ['nested' => true]]);

        $attributes = $this->lastRecord()->getAttributes()->toArray();
        self::assertSame('node-7', $attributes['node']);
        self::assertSame('array', $attributes['meta']);
    }

    protected function setUp(): void
    {
        $this->storage = new ArrayObject();
        $provider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new InMemoryExporter($this->storage)))
            ->build();

        $this->logger = new OtelPsrLogger($provider->getLogger('test'));
    }

    private function lastRecord(): ReadableLogRecord
    {
        $records = $this->storage->getArrayCopy();
        self::assertNotEmpty($records, 'expected a log record to be exported');

        return $records[array_key_last($records)];
    }
}
