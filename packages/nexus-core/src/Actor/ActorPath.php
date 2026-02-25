<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Exception\InvalidActorPathException;
use Override;
use Stringable;

/**
 * @psalm-api
 *
 * Immutable actor path in the hierarchy.
 *
 * Represents a fully-qualified path like `/user/orders/order-123`.
 * The root path is represented as `/`.
 */
final readonly class ActorPath implements Stringable
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
     * @return Option<ActorPath>
     */
    public function parent(): Option
    {
        if ($this->elements === []) {
            /** @var Option<self> $none fp4php returns Option<empty>, covariant to Option<self> */
            $none = Option::none();

            return $none;
        }

        $parentElements = array_slice($this->elements, 0, -1);

        return Option::some(new self($parentElements));
    }

    /**
     * Value equality comparison.
     */
    public function equals(self $other): bool
    {
        return $this->elements === $other->elements;
    }

    /**
     * Returns true if this path is a direct child of the given parent
     * (i.e., depth is exactly parent depth + 1 and shares the same prefix).
     */
    public function isChildOf(self $parent): bool
    {
        return $this->depth() === $parent->depth() + 1
            && $this->startsWith($parent);
    }

    /**
     * Returns true if this path is a descendant (child, grandchild, etc.) of the given ancestor.
     */
    public function isDescendantOf(self $ancestor): bool
    {
        return $this->depth() > $ancestor->depth()
            && $this->startsWith($ancestor);
    }

    /**
     * Returns the depth of this path (0 for root, 1 for `/user`, etc.).
     */
    public function depth(): int
    {
        return count($this->elements);
    }

    /**
     * Checks if this path starts with the given ancestor's elements.
     */
    private function startsWith(self $ancestor): bool
    {
        if ($ancestor->elements === []) {
            return true;
        }

        return array_slice($this->elements, 0, count($ancestor->elements)) === $ancestor->elements;
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
