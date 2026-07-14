<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 test double that records all log entries in order.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{context: array<string, mixed>, level: string, message: string}> */
    public array $logs = [];

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->logs[] = [
            'context' => $context,
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }

    /**
     * @return list<array{context: array<string, mixed>, level: string, message: string}>
     */
    public function logsAtLevel(string $level): array
    {
        return array_values(
            array_filter($this->logs, static fn(array $e): bool => $e['level'] === $level),
        );
    }
}
