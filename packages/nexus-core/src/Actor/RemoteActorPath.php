<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Override;

/**
 * Remote actor path with explicit cluster address plus local actor path.
 *
 * @psalm-api
 */
final readonly class RemoteActorPath implements ActorPathContract
{
    public function __construct(private string $address, private ActorPath $path) {}

    public static function fromAddress(string $address, ActorPath $path): self
    {
        return new self($address, $path);
    }

    public static function forWorker(int $workerId, ActorPath $path): self
    {
        return new self("cluster://worker-{$workerId}", $path);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function localPath(): ActorPath
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
                fn(ActorPathContract $parent): self => new self(
                    $this->address,
                    ActorPath::fromString((string) $parent),
                ),
            );
    }

    #[Override]
    public function equals(ActorPathContract $other): bool
    {
        return $other instanceof self
            && $this->address === $other->address
            && $this->path->equals($other->path);
    }

    #[Override]
    public function isChildOf(ActorPathContract $parent): bool
    {
        if (!$parent instanceof self || $this->address !== $parent->address) {
            return false;
        }

        return $this->path->isChildOf($parent->path);
    }

    #[Override]
    public function isDescendantOf(ActorPathContract $ancestor): bool
    {
        if (!$ancestor instanceof self || $this->address !== $ancestor->address) {
            return false;
        }

        return $this->path->isDescendantOf($ancestor->path);
    }

    #[Override]
    public function depth(): int
    {
        return $this->path->depth();
    }

    #[Override]
    public function __toString(): string
    {
        return $this->address . (string) $this->path;
    }
}
