<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory logger for test assertions.
 *
 * Captures all log entries so tests can inspect level and message.
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $logs = [];

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * Returns true if any log entry matches the given level and message substring.
     */
    public function hasLogMatching(string $level, string $messageContains): bool
    {
        foreach ($this->logs as $entry) {
            if ($entry['level'] === $level && str_contains($entry['message'], $messageContains)) {
                return true;
            }
        }

        return false;
    }
}
