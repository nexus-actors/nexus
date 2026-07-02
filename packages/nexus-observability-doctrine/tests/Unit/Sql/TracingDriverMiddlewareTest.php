<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit\Sql;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\Sql\TracingDriverMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

#[CoversClass(TracingDriverMiddleware::class)]
final class TracingDriverMiddlewareTest extends TestCase
{
    #[Test]
    public function spansExecutedStatementsWithParameterizedSql(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $config = new Configuration();
        $config->setMiddlewares([new TracingDriverMiddleware($observability)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'alice']);
        $rows = $connection->executeQuery('SELECT name FROM t WHERE id = ?', [1])->fetchAllAssociative();
        self::assertSame('alice', $rows[0]['name']); // real query executed (delegation)

        $tracerProvider->forceFlush();
        $spans = $spanExporter->getSpans();
        self::assertNotEmpty($spans);

        // A span carrying the parameterized SELECT (placeholders, NOT bound values).
        $selectSpans = array_values(array_filter(
            $spans,
            static fn ($span): bool => $span->getAttributes()->get('db.query.text') === 'SELECT name FROM t WHERE id = ?',
        ));
        self::assertNotEmpty($selectSpans);
        self::assertSame(1, $selectSpans[0]->getKind()); // CLIENT (OpenTelemetry SDK KIND_CLIENT = 1)
        self::assertSame('SELECT', $selectSpans[0]->getAttributes()->get('db.operation.name'));

        // No bound value 'alice' or '1' leaked into any db.query.text.
        foreach ($spans as $span) {
            $text = $span->getAttributes()->get('db.query.text');

            if ($text !== null) {
                self::assertStringNotContainsString('alice', (string) $text);
            }
        }
    }
}
