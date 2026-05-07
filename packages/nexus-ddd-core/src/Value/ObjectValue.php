<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use ReflectionObject;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Composite value object base — the workhorse for multi-field VOs (Address,
 * Money, DateRange, etc.). Equality is structural across all declared
 * instance properties of the same runtime class.
 *
 * Subclasses MUST be `final readonly class` and MUST declare their fields
 * via constructor promotion with names matching the constructor parameters
 * (the standard PHP 8+ shape). `with()` relies on this convention to
 * produce non-destructive updates.
 *
 * Composite VOs expose their fields as semantic, named domain attributes
 * (e.g., `$address->city`) — distinct from `WrappedValue`'s encapsulated
 * single-value design.
 *
 * Example:
 *
 *     final readonly class Money extends ObjectValue {
 *         public function __construct(
 *             public int $amount,
 *             public string $currency,
 *         ) {}
 *     }
 *
 *     $price = new Money(1000, 'EUR');
 *     $discounted = $price->with(['amount' => 800]);   // new Money(800, 'EUR')
 */
abstract readonly class ObjectValue
{
    /**
     * Structural equality. Two instances are equal iff they are the same
     * runtime class and every declared instance property compares strictly
     * equal (`===`).
     *
     * @psalm-suppress ImpureMethodCall,MixedAssignment
     */
    public function equals(ObjectValue $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }
        $thisReflection = new ReflectionObject($this);
        $otherReflection = new ReflectionObject($other);
        foreach ($thisReflection->getProperties() as $prop) {
            $name = $prop->getName();
            $thisVal = $prop->getValue($this);
            $otherProp = $otherReflection->getProperty($name);
            $otherVal = $otherProp->getValue($other);
            if ($thisVal !== $otherVal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Non-destructive update — return a new instance of the same class with
     * the supplied properties overridden. Unspecified properties retain
     * their current values.
     *
     * Subclass constructors MUST accept named arguments matching their
     * promoted-property names (the standard `final readonly class
     * Foo { public function __construct(public X $x) {} }` shape).
     *
     * @param array<string, mixed> $changes property-name → new-value map
     * @psalm-suppress ImpureMethodCall,MixedAssignment,MixedArgument,UnsafeInstantiation
     */
    #[\NoDiscard('with() returns the updated value object — discarding it loses the change')]
    public function with(array $changes): static
    {
        $reflection = new ReflectionObject($this);
        $values = [];

        foreach ($reflection->getProperties() as $prop) {
            $name = $prop->getName();
            $values[$name] = array_key_exists($name, $changes)
                ? $changes[$name]
                : $prop->getValue($this);
        }

        /** @var static */
        return new (static::class)(...$values);
    }
}
