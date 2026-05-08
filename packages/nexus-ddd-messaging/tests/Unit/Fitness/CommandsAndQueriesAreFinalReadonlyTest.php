<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Fitness;

use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

#[CoversNothing]
final class CommandsAndQueriesAreFinalReadonlyTest extends TestCase
{
    #[Test]
    public function everyConcreteCommandAndQueryInSrcIsFinalReadonly(): void
    {
        $srcDir = __DIR__ . '/../../../src';
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertNotFalse($contents);

            if (! preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)) {
                continue;
            }

            if (! preg_match('/(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $contents, $clsMatch)) {
                continue;
            }

            $fqn = trim($nsMatch[1]) . '\\' . $clsMatch[1];

            if (! class_exists($fqn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqn);

            if (
                ! $reflection->implementsInterface(Command::class)
                && ! $reflection->implementsInterface(Query::class)
            ) {
                continue;
            }

            if ($reflection->isInterface() || $reflection->isAbstract()) {
                continue;
            }

            self::assertTrue($reflection->isFinal(), $fqn . ' must be final');
            self::assertTrue($reflection->isReadOnly(), $fqn . ' must be readonly');
        }

        self::assertTrue(true);
    }
}
