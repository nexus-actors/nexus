<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Generic extractor — accesses the protected `getValue()` of any WrappedValue
 * via the inheritance trick (extending WrappedValue gives this class access
 * to any WrappedValue instance's protected methods).
 *
 * Never instantiated; its only purpose is to inherit the access privilege.
 *
 * Application/infrastructure code uses this (or one of the typed extractors)
 * to read raw inner values from value objects without value objects exposing
 * `value()` publicly. Mirrors PF's `Value/Extractor/ValueExtractor` pattern.
 *
 * @template-extends WrappedValue<mixed>
 */
final readonly class ValueExtractor extends WrappedValue
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
        // never called — class exists purely to inherit access to WrappedValue::getValue().
        // Parent constructor deliberately not called; readonly $value remains uninitialized
        // because no instance is ever constructed.
    }

    /**
     * @template T
     * @param WrappedValue<T> $valueObject
     * @return T
     */
    #[NoDiscard('extract() returns the inner value — its return is the entire purpose of the call')]
    public static function extract(WrappedValue $valueObject): mixed
    {
        return $valueObject->getValue();
    }
}
