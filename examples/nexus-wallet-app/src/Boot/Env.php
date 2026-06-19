<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

/**
 * Tiny env reader used by the typed config sections. Returns the env value
 * if set and non-empty, otherwise the default. Centralised so the "what
 * counts as missing" rule lives in exactly one place.
 */
final class Env
{
    /**
     * @param non-empty-string $default
     * @return non-empty-string
     */
    public static function get(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === ''
            ? $default
            : $value;
    }

    public static function int(string $name, int $default): int
    {
        return (int) self::get($name, (string) $default);
    }
}
