<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

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
