<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Monadial\Nexus\Runtime\Duration;

use function sprintf;

/**
 * Worker-recycling limits evaluated by the LifecycleWatchdog. A null limit is
 * disabled. All comparisons are inclusive: reaching the limit breaches it.
 *
 * @psalm-api
 */
final readonly class LifecycleThresholds
{
    private function __construct(public ?int $memoryLimitBytes, public ?int $messageLimit, public ?Duration $timeLimit,) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function withMemoryLimit(int $bytes): self
    {
        return new self($bytes, $this->messageLimit, $this->timeLimit);
    }

    public function withMessageLimit(int $count): self
    {
        return new self($this->memoryLimitBytes, $count, $this->timeLimit);
    }

    public function withTimeLimit(Duration $limit): self
    {
        return new self($this->memoryLimitBytes, $this->messageLimit, $limit);
    }

    /**
     * Returns a human-readable breach description, or null when no threshold
     * is breached.
     */
    public function breachReason(int $memoryBytes, Duration $uptime, int $processedCount): ?string
    {
        if ($this->memoryLimitBytes !== null && $memoryBytes >= $this->memoryLimitBytes) {
            return sprintf('memory usage %d bytes reached the %d byte limit', $memoryBytes, $this->memoryLimitBytes);
        }

        if ($this->messageLimit !== null && $processedCount >= $this->messageLimit) {
            return sprintf('processed %d messages, reaching the limit of %d', $processedCount, $this->messageLimit);
        }

        if ($this->timeLimit !== null && !$uptime->isLessThan($this->timeLimit)) {
            return sprintf('uptime %ds reached the %ds limit', $uptime->toSeconds(), $this->timeLimit->toSeconds());
        }

        return null;
    }
}
