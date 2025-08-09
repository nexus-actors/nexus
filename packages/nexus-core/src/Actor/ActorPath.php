<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Exception\InvalidActorPathException;

/**
 * Immutable actor path in the hierarchy.
 * Stub — full implementation in Task 3.
 */
final readonly class ActorPath implements \Stringable
{
    /** @var non-empty-list<string> */
    private array $elements;

    private function __construct(
        /** @var non-empty-list<string> */
        array $elements,
    ) {
        $this->elements = $elements;
    }

    public static function root(): self
    {
        return new self(['/']);
    }

    /**
     * @throws InvalidActorPathException (unchecked — logic error, but used during construction)
     */
    public static function fromString(string $path): self
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidActorPathException($path);
        }

        return new self(explode('/', ltrim($path, '/')) ?: ['/']);
    }

    public function __toString(): string
    {
        if ($this->elements === ['/']) {
            return '/';
        }

        return '/' . implode('/', $this->elements);
    }
}
