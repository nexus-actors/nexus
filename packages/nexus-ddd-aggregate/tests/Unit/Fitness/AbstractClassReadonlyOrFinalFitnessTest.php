<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Fitness;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
final class AbstractClassReadonlyOrFinalFitnessTest extends TestCase
{
    /**
     * Every concrete `class` declaration in src/ must carry an `abstract` or
     * `final` modifier. Interfaces and traits are not classes and are skipped
     * by the regex (it matches the literal `class` keyword only).
     */
    #[Test]
    public function everySrcClassIsAbstractOrFinal(): void
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

            $found = preg_match_all(
                '/^(abstract\s+|final\s+)?(?:readonly\s+)?class\s+(\w+)/m',
                $contents,
                $matches,
                PREG_SET_ORDER,
            );

            if ($found === false) {
                continue;
            }

            foreach ($matches as $match) {
                $modifier = trim($match[1]);

                if ($modifier === '') {
                    $offenders[] = sprintf('%s: %s', $file->getPathname(), $match[2]);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Every concrete class in src/ must be declared abstract or final (project "final by default" rule).',
        );
    }
}
