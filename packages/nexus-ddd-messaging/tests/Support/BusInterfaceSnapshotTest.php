<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedQueryBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Pins every bus interface's public-method signatures into a snapshot
 * fixture. Any drift fails CI; intentional changes regenerate the
 * fixture by setting `UPDATE_BUS_SNAPSHOT=1` before running.
 */
final class BusInterfaceSnapshotTest extends TestCase
{
    private const string SNAPSHOT_PATH = __DIR__ . '/Fixture/bus-interfaces.snapshot.txt';

    private const array INTERFACES = [
        CommandBus::class,
        QueryBus::class,
        EventBus::class,
        EnvelopedCommandBus::class,
        EnvelopedQueryBus::class,
        EnvelopedEventBus::class,
    ];

    #[Test]
    public function busSignaturesMatchSnapshot(): void
    {
        $current = self::computeSnapshot();

        if (getenv('UPDATE_BUS_SNAPSHOT') === '1') {
            $dir = dirname(self::SNAPSHOT_PATH);

            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            file_put_contents(self::SNAPSHOT_PATH, $current);
            self::markTestIncomplete('Snapshot regenerated; rerun without UPDATE_BUS_SNAPSHOT to verify.');
        }

        self::assertFileExists(self::SNAPSHOT_PATH);

        $expected = file_get_contents(self::SNAPSHOT_PATH);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $current,
            'Bus interface signatures drifted. Inspect the diff; if intentional, '
            . 'rerun with UPDATE_BUS_SNAPSHOT=1 to regenerate the fixture.',
        );
    }

    private static function computeSnapshot(): string
    {
        $lines = [];

        foreach (self::INTERFACES as $iface) {
            $reflection = new ReflectionClass($iface);
            $extends = $reflection->getInterfaceNames();
            sort($extends);
            $lines[] = sprintf('interface %s extends %s', $iface, implode(', ', $extends));

            $methods = $reflection->getMethods();
            usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b) => $a->getName() <=> $b->getName());

            foreach ($methods as $method) {
                $params = array_map(
                    static fn(ReflectionParameter $p) => self::renderType($p->getType()) . ' $' . $p->getName(),
                    $method->getParameters(),
                );

                $lines[] = sprintf(
                    '  %s(%s): %s',
                    $method->getName(),
                    implode(', ', $params),
                    self::renderType($method->getReturnType()),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function renderType(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = array_map(static fn(ReflectionType $t) => self::renderType($t), $type->getTypes());

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() ? '?' : '') . $type->getName();
        }

        return (string) $type;
    }
}
