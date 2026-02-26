<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Override;

/**
 * Explicit local actor path wrapper.
 *
 * @psalm-api
 */
final readonly class LocalActorPath implements ActorPathContract
{
    public function __construct(private ActorPath $path) {}

    public static function root(): self
    {
        return new self(ActorPath::root());
    }

    public static function fromString(string $path): self
    {
        return new self(ActorPath::fromString($path));
    }

    public function toActorPath(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function name(): string
    {
        return $this->path->name();
    }

    #[Override]
    public function parent(): Option
    {
        return $this->path
            ->parent()
            ->map(
                static fn(ActorPathContract $parent): self => new self(ActorPath::fromString((string) $parent)),
            );
    }

    #[Override]
    public function equals(ActorPathContract $other): bool
    {
        return $this->path->equals($other);
    }

    #[Override]
    public function isChildOf(ActorPathContract $parent): bool
    {
        return $this->path->isChildOf($parent);
    }

    #[Override]
    public function isDescendantOf(ActorPathContract $ancestor): bool
    {
        return $this->path->isDescendantOf($ancestor);
    }

    #[Override]
    public function depth(): int
    {
        return $this->path->depth();
    }

    #[Override]
    public function __toString(): string
    {
        return (string) $this->path;
    }
}
