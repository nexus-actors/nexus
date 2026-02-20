<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;

/** @psalm-api */
final readonly class SnapshotStrategy
{
    /** @param Closure(object, object, int): bool $predicate */
    private function __construct(private Closure $predicate) {}

    public static function everyN(int $n): self
    {
        return new self(static fn(object $state, object $event, int $seqNr): bool => $seqNr % $n === 0);
    }

    public static function never(): self
    {
        return new self(static fn(): bool => false);
    }

    /** @param Closure(object, object, int): bool $fn */
    public static function predicate(Closure $fn): self
    {
        return new self($fn);
    }

    public function shouldSnapshot(object $state, object $event, int $sequenceNr): bool
    {
        return ($this->predicate)($state, $event, $sequenceNr);
    }
}
