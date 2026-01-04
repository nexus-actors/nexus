<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;

readonly class SnapshotStrategy
{
    private function __construct(
        private Closure $predicate,
    ) {}

    public static function everyN(int $n): self
    {
        return new self(fn(object $state, object $event, int $seqNr): bool => $seqNr % $n === 0);
    }

    public static function never(): self
    {
        return new self(fn(): bool => false);
    }

    public static function predicate(Closure $fn): self
    {
        return new self($fn);
    }

    public function shouldSnapshot(object $state, object $event, int $sequenceNr): bool
    {
        return ($this->predicate)($state, $event, $sequenceNr);
    }
}
