<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

readonly class RetentionPolicy
{
    private function __construct(
        public int $keepSnapshots,
        public bool $deleteEventsToSnapshot,
    ) {
    }

    public static function none(): self
    {
        return new self(keepSnapshots: PHP_INT_MAX, deleteEventsToSnapshot: false);
    }

    public static function snapshotAndEvents(int $keepSnapshots, bool $deleteEventsTo = false): self
    {
        return new self($keepSnapshots, $deleteEventsTo);
    }
}
