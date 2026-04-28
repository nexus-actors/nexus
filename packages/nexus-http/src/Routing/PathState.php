<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use function array_slice;
use function explode;
use function trim;

final readonly class PathState
{
    /** @param list<string> $remaining */
    public function __construct(public array $remaining) {}

    public static function fromPath(string $path): self
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return new self([]);
        }

        return new self(explode('/', $trimmed));
    }

    public function consume(string $segment): ?self
    {
        if ($this->remaining === [] || $this->remaining[0] !== $segment) {
            return null;
        }

        return new self(array_slice($this->remaining, 1));
    }

    /** @return array{0: string, 1: self}|null */
    public function consumeAny(): ?array
    {
        if ($this->remaining === []) {
            return null;
        }

        return [
            $this->remaining[0],
            new self(array_slice($this->remaining, 1)),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->remaining === [];
    }
}
