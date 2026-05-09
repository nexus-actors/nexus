<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Fitness;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
final class PackageDependencyFitnessTest extends TestCase
{
    /**
     * Top-level namespace prefixes that the package's src/ must NOT import from.
     *
     * The aggregate package is a pure-DDD library: it depends only on its sibling
     * ddd-core, on fp4php, on PSR contracts, and on PHP built-ins. It must not
     * pull in actor-system internals or any other DDD package.
     */
    private const array FORBIDDEN_PREFIXES = [
        'Monadial\\Nexus\\Cluster\\',
        'Monadial\\Nexus\\Core\\',
        'Monadial\\Nexus\\Ddd\\Messaging\\',
        'Monadial\\Nexus\\Persistence\\',
        'Monadial\\Nexus\\Runtime',
    ];

    #[Test]
    public function srcDirectoryContainsOnlyAllowedImports(): void
    {
        $srcDir = __DIR__ . '/../../../src';
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        $offenders = [];

        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertNotFalse($contents);

            if (preg_match_all('/^use\s+([^\s;]+)/m', $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $import) {
                foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($import, $prefix)) {
                        $offenders[] = sprintf('%s: %s', $file->getPathname(), $import);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Package src/ must not import from forbidden Nexus namespaces (actor-system, sibling DDD packages).',
        );
    }
}
