<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use OpenTelemetry\API\Logs\LoggerInterface as OtelLogger;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use Override;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function get_debug_type;
use function is_scalar;

/**
 * @psalm-api
 *
 * PSR-3 logger that emits each record to an OpenTelemetry {@see OtelLogger}, so application
 * and actor-system logs are exported over OTLP (e.g. to Loki via a collector) and — because
 * the SDK stamps the active trace context onto each record — correlate with the spans that
 * were in flight when they were written.
 */
final class OtelPsrLogger extends AbstractLogger
{
    public function __construct(private readonly OtelLogger $logger) {}

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelName = (string) $level;

        $record = new LogRecord((string) $message);
        $record->setSeverityNumber(Severity::fromPsr3($levelName));
        $record->setSeverityText($levelName);

        if ($context !== []) {
            $record->setAttributes($this->stringifyContext($context));
        }

        $this->logger->emit($record);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, scalar>
     */
    private function stringifyContext(array $context): array
    {
        return array_map(
            static fn(mixed $value): bool|float|int|string => match (true) {
                is_scalar($value) => $value,
                $value instanceof Stringable => (string) $value,
                default => get_debug_type($value),
            },
            $context,
        );
    }
}
