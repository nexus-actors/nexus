<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

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

    /** Cached root instance — avoids an allocation on every tell()/Envelope::of() call. */
    private static ?self $cachedRoot = null;

    /**
     * @param list<string> $elements Path segments (empty for root, e.g. ['user', 'orders'] for /user/orders)
     */
    private function __construct(private array $elements) {}

    /**
     * Creates the root path `/`.
     *
     * The instance is cached per thread (PHP ZTS gives each thread its own
     * static storage), so repeated calls pay only a null-check.
     */
    public static function root(): self
    {
        if (self::$cachedRoot === null) {
            self::$cachedRoot = new self([]);
        }

        return self::$cachedRoot;
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
     * Returns the parent path, or null for the root path.
     */
    #[Override]
    public function parent(): ?ActorPathContract
    {
        if ($this->elements === []) {
            return null;
        }

        return new self(array_slice($this->elements, 0, -1));
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

    /**
     * Compact serialization — stores just the path string instead of the private
     * elements array with its full namespace-qualified field key.
     *
     * Before: O:...:1:{s:50:"\0...\0elements";a:2:{i:0;s:4:"user";i:1;s:7:"shard-0";}}
     * After:  O:...:1:{s:1:"p";s:14:"/user/shard-0";}
     *
     * Saves ~130 bytes per Envelope passed through Thread\Queue, improving
     * cross-thread throughput.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['p' => (string) $this];
    }

    /**
     * @param array<string, string> $data
     */
    public function __unserialize(array $data): void
    {
        $path = $data['p'];

        $this->elements = $path === '/'
            ? []
            : explode('/', substr($path, 1));
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
