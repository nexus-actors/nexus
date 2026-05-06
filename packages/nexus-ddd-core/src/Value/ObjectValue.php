<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use ReflectionObject;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Composite value object base. Equality is structural — all declared instance
 * properties of the same runtime class are compared. Subclasses MUST be
 * `final readonly class` with `public readonly` properties (composite VOs
 * expose their fields as semantic, named domain attributes; this differs from
 * WrappedValue's encapsulated single-value design).
 */
abstract readonly class ObjectValue
{
    /**
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
}
