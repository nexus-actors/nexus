<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test fixture: a PSR-3 logger that captures every call as a `[level,
 * message, context]` tuple. Tests assert against `$records`. No
 * framework-specific log lib enters the package's classpath.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'context' => $context,
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }
}
