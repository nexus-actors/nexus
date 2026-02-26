<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Exception\InvalidActorPathException;
use Override;

/**
 * @psalm-api
 *
 * Immutable actor path in the hierarchy.
 *
 * Represents a fully-qualified path like `/user/orders/order-123`.
 * The root path is represented as `/`.
 */
final class ActorPath implements ActorPathContract
{
    private const string NAME_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    /**
     * @param list<string> $elements Path segments (empty for root, e.g. ['user', 'orders'] for /user/orders)
     */
    private function __construct(private array $elements) {}

    /**
     * Creates the root path `/`.
     */
    public static function root(): self
    {
        return new self([]);
    }

    /**
     * Parses an actor path from a string like `/user/orders`.
     *
     * @throws InvalidActorPathException If the path is empty or does not start with `/`
     */
    public static function fromString(string $path): self
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidActorPathException($path);
        }

        if ($path === '/') {
            return self::root();
        }

        $segments = explode('/', substr($path, 1));

        return new self($segments);
    }

    /**
     * Creates a child path by appending a name segment.
     *
     * @throws InvalidActorPathException If the name contains invalid characters
     */
    public function child(string $name): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidActorPathException((string) $this . '/' . $name);
        }

        return new self([...$this->elements, $name]);
    }

    /**
     * Returns the last segment of the path (`'/'` for root).
     */
    #[Override]
    public function name(): string
    {
        if ($this->elements === []) {
            return '/';
        }

        return $this->elements[array_key_last($this->elements)];
    }

    /**
     * Returns the parent path, or None for the root path.
     *
     * @return Option<ActorPathContract>
     */
    #[Override]
    public function parent(): Option
    {
        if ($this->elements === []) {
            /** @var Option<ActorPathContract> $none fp4php returns Option<empty>, covariant to Option<ActorPathContract> */
            $none = Option::none();

            return $none;
        }

        $parentElements = array_slice($this->elements, 0, -1);

        return Option::some(new self($parentElements));
    }

    /**
     * Value equality comparison.
     */
    #[Override]
    public function equals(ActorPathContract $other): bool
    {
        return $this->elements === self::elementsFrom($other);
    }

    /**
     * Returns true if this path is a direct child of the given parent
     * (i.e., depth is exactly parent depth + 1 and shares the same prefix).
     */
    #[Override]
    public function isChildOf(ActorPathContract $parent): bool
    {
        return $this->depth() === $parent->depth() + 1
            && $this->startsWith($parent);
    }

    /**
     * Returns true if this path is a descendant (child, grandchild, etc.) of the given ancestor.
     */
    #[Override]
    public function isDescendantOf(ActorPathContract $ancestor): bool
    {
        return $this->depth() > $ancestor->depth()
            && $this->startsWith($ancestor);
    }

    /**
     * Returns the depth of this path (0 for root, 1 for `/user`, etc.).
     */
    #[Override]
    public function depth(): int
    {
        return count($this->elements);
    }

    /**
     * Checks if this path starts with the given ancestor's elements.
     */
    private function startsWith(ActorPathContract $ancestor): bool
    {
        $ancestorElements = self::elementsFrom($ancestor);

        if ($ancestorElements === []) {
            return true;
        }

        return array_slice($this->elements, 0, count($ancestorElements)) === $ancestorElements;
    }

    /**
     * @return list<string>
     */
    private static function elementsFrom(ActorPathContract $path): array
    {
        if ($path instanceof self) {
            return $path->elements;
        }

        $stringPath = (string) $path;

        if ($stringPath === '/') {
            return [];
        }

        /** @var list<string> */
        return explode('/', substr($stringPath, 1));
    }

    #[Override]
    public function __toString(): string
    {
        if ($this->elements === []) {
            return '/';
        }

        return '/' . implode('/', $this->elements);
    }
}
