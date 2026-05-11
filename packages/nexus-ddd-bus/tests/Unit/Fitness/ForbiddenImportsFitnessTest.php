<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Fitness;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
final class ForbiddenImportsFitnessTest extends TestCase
{
    private const array FORBIDDEN_PATTERNS = [
        '/^use\s+Amp\\\\/m' => 'Amp',
        '/^use\s+Doctrine\\\\/m' => 'Doctrine',
        '/^use\s+GuzzleHttp\\\\/m' => 'GuzzleHttp',
        '/^use\s+Illuminate\\\\/m' => 'Illuminate (Laravel)',
        '/^use\s+Laravel\\\\/m' => 'Laravel',
        '/^use\s+Monadial\\\\Nexus\\\\Ddd\\\\Aggregate\\\\/m' => 'Monadial\\Nexus\\Ddd\\Aggregate (aggregate package)',
        '/^use\s+Monadial\\\\Nexus\\\\Persistence\\\\/m' => 'Monadial\\Nexus\\Persistence',
        '/^use\s+Monolog\\\\/m' => 'Monolog',
        '/^use\s+React\\\\/m' => 'React',
        '/^use\s+Symfony\\\\(?!Component\\\\Console\\\\)/m' => 'Symfony (except Component\\Console)',
    ];

    #[Test]
    public function noForbiddenFrameworkImportsInPackageSrc(): void
    {
        $srcDir = __DIR__ . '/../../../src';
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertNotFalse($contents);

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
                self::assertSame(
                    0,
                    preg_match($pattern, $contents),
                    sprintf('%s imports %s — forbidden in nexus-ddd-bus.', $file->getPathname(), $label),
                );
            }
        }

        self::assertTrue(true);
    }
}
