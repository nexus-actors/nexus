<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Composite identifier whose components are themselves Identifier instances.
 *
 * Default canonical format: components joined by `:`, each component's
 * `value()` URL-encoded. Subclasses pass typed Identifier components to the
 * constructor and MUST implement their own `fromString` (the abstract base
 * cannot know which concrete Identifier type each component is).
 */
abstract readonly class AbstractCompositeIdentifier implements CompositeIdentifier
{
    /** @param array<string, Identifier> $components */
    protected function __construct(private array $components) {}

    /** @return array<string, Identifier> */
    #[\Override]
    final public function components(): array
    {
        return $this->components;
    }

    #[\Override]
    public function value(): string
    {
        return implode(
            ':',
            array_map(
                static fn(Identifier $id): string => rawurlencode($id->value()),
                array_values($this->components),
            ),
        );
    }

    #[\Override]
    public function equals(Identifier $other): bool
    {
        if (! $other instanceof self) {
            return false;
        }

        if ($other::class !== static::class) {
            return false;
        }

        $otherComponents = $other->components();

        if (array_keys($otherComponents) !== array_keys($this->components)) {
            return false;
        }

        foreach ($this->components as $key => $component) {
            if (! $component->equals($otherComponents[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Subclasses MUST implement: parse $value, instantiate each component's
     * concrete Identifier via its own ::fromString, then call the subclass
     * constructor with typed components.
     *
     * The abstract base cannot provide a default — it doesn't know which
     * concrete Identifier types each component should be.
     */
    #[\Override]
    abstract public static function fromString(string $value): static;
}
