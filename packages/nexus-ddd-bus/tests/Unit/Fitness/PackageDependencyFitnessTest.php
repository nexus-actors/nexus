<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Fitness;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
final class PackageDependencyFitnessTest extends TestCase
{
    /**
     * Whitelist of vendor namespace prefixes the bus package's src/ may import from.
     *
     * The bus package is a dispatch fabric: it depends on its sibling DDD packages
     * (ddd-core, ddd-messaging), on fp4php for Option/Either, on PSR contracts
     * (logger, clock, container, event-dispatcher), on Monadial\Duration for
     * FiniteDuration/TimeUnit, on Symfony\Component\Console for CLI commands,
     * and on Nette\PhpGenerator for routing-table code generation.
     *
     * Anything not matching this whitelist (and not a single-segment global-namespace
     * import — PHP built-in class, function, or constant) is an architectural
     * drift to surface.
     */
    private const array ALLOWED_PREFIXES = [
        'Fp\\Functional\\',
        'Monadial\\Duration\\',
        'Monadial\\Nexus\\Ddd\\Bus\\',
        'Monadial\\Nexus\\Ddd\\Core\\',
        'Monadial\\Nexus\\Ddd\\Messaging\\',
        'Nette\\PhpGenerator\\',
        'Psr\\Clock\\',
        'Psr\\Container\\',
        'Psr\\EventDispatcher\\',
        'Psr\\Log\\',
        'Symfony\\Component\\Console\\',
    ];

    #[Test]
    public function srcDirectoryContainsOnlyWhitelistedImports(): void
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

            /**
             * Matches top-level `use` statements only (line-anchored). Captures the
             * fully-qualified name after the optional `function`/`const` keyword.
             * Closure `use (...)` clauses are not at start-of-line so they don't match.
             */
            if (preg_match_all('/^use\s+(?:function\s+|const\s+)?([^\s;]+)/m', $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $import) {
                if ($this->isAllowed($import)) {
                    continue;
                }

                $offenders[] = sprintf('%s: use %s', $file->getPathname(), $import);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Package src/ may only import from the whitelisted vendor namespaces or the PHP global namespace.',
        );
    }

    private function isAllowed(string $import): bool
    {
        if (! str_contains($import, '\\')) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($import, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
