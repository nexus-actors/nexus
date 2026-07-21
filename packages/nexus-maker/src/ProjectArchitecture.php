<?php

declare(strict_types=1);

namespace Nexus\Maker;

use InvalidArgumentException;
use JsonException;

use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Resolves the application architecture recorded by nexus:setup in
 * composer.json (extra.nexus.architecture). Generators use it to decide
 * where and how to scaffold. Only 'minimal' exists today; a DDD reference
 * architecture will join as a preset.
 *
 * @psalm-api consumed by the make:* commands
 */
final class ProjectArchitecture
{
    public const string DEFAULT = 'minimal';

    public const array SUPPORTED = ['minimal'];

    /**
     * @throws InvalidArgumentException when the recorded architecture is not supported by this maker version
     */
    public static function resolve(string $projectDir): string
    {
        $path = $projectDir . '/composer.json';

        if (!file_exists($path)) {
            return self::DEFAULT;
        }

        try {
            /** @var mixed $composer */
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::DEFAULT;
        }

        /** @var mixed $extra */
        $extra = null;

        if (is_array($composer)) {
            /** @var mixed $extra */
            $extra = $composer['extra'] ?? null;
        }

        /** @var mixed $nexus */
        $nexus = null;

        if (is_array($extra)) {
            /** @var mixed $nexus */
            $nexus = $extra['nexus'] ?? null;
        }

        /** @var mixed $architecture */
        $architecture = self::DEFAULT;

        if (is_array($nexus)) {
            /** @var mixed $architecture */
            $architecture = $nexus['architecture'] ?? self::DEFAULT;
        }

        if (!is_string($architecture)) {
            $architecture = 'non-string';
        }

        if (!in_array($architecture, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown architecture "%s" in composer.json (extra.nexus.architecture) — this maker version supports: %s.',
                $architecture,
                implode(', ', self::SUPPORTED),
            ));
        }

        return $architecture;
    }
}
