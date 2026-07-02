<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Throwable;

use function preg_match;
use function strtoupper;

/**
 * @internal
 *
 * Shared, fail-isolated span helpers for SQL execution. `db.query.text` is the
 * parameterized SQL (placeholders only — no bound values).
 */
final class SqlSpan
{
    public static function start(Observability $observability, string $sql): ?Span
    {
        try {
            $operation = self::operation($sql);

            return $observability->tracer()->startSpan(
                $operation === '' ? 'SQL' : $operation,
                SpanKind::Client,
                [
                    'db.operation.name' => $operation,
                    'db.query.text' => $sql,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    public static function error(?Span $span, Throwable $e): void
    {
        try {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        } catch (Throwable) {
            // Telemetry must never break the query.
        }
    }

    public static function end(?Span $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Telemetry must never break the query.
        }
    }

    private static function operation(string $sql): string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $m) === 1) {
            return strtoupper($m[1]);
        }

        return '';
    }
}
