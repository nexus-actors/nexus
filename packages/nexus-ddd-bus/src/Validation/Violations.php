<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Validation;

use NoDiscard;

use function array_filter;
use function array_values;
use function count;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable collection of `Violation`s. The bus's ValidationMiddleware
 * builds one of these via the application's `Validator` and — if non-empty —
 * lifts it to a `ValidationFailedException`.
 */
final readonly class Violations
{
    /** @param list<Violation> $violations */
    public function __construct(public array $violations) {}

    #[NoDiscard('Violations::empty returns the empty collection — assign or use it')]
    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    /** @return list<Violation> */
    public function all(): array
    {
        return $this->violations;
    }

    public function count(): int
    {
        return count($this->violations);
    }

    #[NoDiscard('forPath returns a filtered collection — ignoring it loses the result')]
    public function forPath(string $path): self
    {
        return new self(array_values(array_filter(
            $this->violations,
            static fn(Violation $v): bool => $v->path === $path,
        )));
    }

    #[NoDiscard('merge returns a new collection — the originals are unchanged')]
    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }
}
