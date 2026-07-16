<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

use InvalidArgumentException;

/**
 * Parses human-readable memory-limit strings into bytes.
 *
 * Accepted forms:
 * - Plain integers: `"1024"` → 1 024 bytes
 * - K suffix: `"128K"` → 131 072 bytes
 * - M suffix: `"128M"` → 134 217 728 bytes
 * - G suffix: `"1G"` → 1 073 741 824 bytes
 * Suffixes are case-insensitive.
 *
 * Example:
 * ```php
 * $bytes = MemoryLimit::parse('256M'); // 268 435 456
 * ```
 *
 * @psalm-api
 *
 * A case-less enum: uninstantiable by the language, exists purely as a
 * namespace for the static parser.
 */
enum MemoryLimit
{
    /**
     * @throws InvalidArgumentException When the value is not a recognised memory-limit string.
     */
    public static function parse(string $value): int
    {
        if ($value === '') {
            throw new InvalidArgumentException('Memory limit must not be empty.');
        }

        if (preg_match('/^(\d+)([KMG]?)$/i', $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Invalid memory limit '{$value}'. Expected a plain integer or a value with K/M/G suffix (e.g. '128M', '1G').",
            );
        }

        $amount = (int) $matches[1];
        $suffix = strtoupper($matches[2]);

        return match ($suffix) {
            'K' => $amount * 1024,
            'M' => $amount * 1024 * 1024,
            'G' => $amount * 1024 * 1024 * 1024,
            default => $amount,
        };
    }
}
