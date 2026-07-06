<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Serialization\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{context: array<string, mixed>, level: string, message: string}> */
    public array $logs = [];

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->logs[] = [
            'context' => $context,
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }

    public function hasWarning(string $message): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === 'warning' && $log['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
