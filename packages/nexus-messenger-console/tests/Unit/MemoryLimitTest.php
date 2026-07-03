<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Messenger\Console\MemoryLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoryLimit::class)]
final class MemoryLimitTest extends TestCase
{
    /**
     */
    #[Test]
    #[DataProvider('validValues')]
    public function parsesValidValues(string $input, int $expected): void
    {
        self::assertSame($expected, MemoryLimit::parse($input));
    }

    /**
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function rejectsInvalidValues(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        MemoryLimit::parse($input);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function validValues(): array
    {
        return [
            'G suffix lowercase' => ['1g', 1024 * 1024 * 1024],
            'G suffix uppercase' => ['2G', 2 * 1024 * 1024 * 1024],
            'K suffix lowercase' => ['1k', 1024],
            'K suffix uppercase' => ['128K', 128 * 1024],
            'M suffix lowercase' => ['1m', 1024 * 1024],
            'M suffix uppercase' => ['128M', 128 * 1024 * 1024],
            'plain integer' => ['1024', 1024],
            'zero' => ['0', 0],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'empty string' => [''],
            'float' => ['1.5M'],
            'letters only' => ['abc'],
            'negative' => ['-1M'],
            'no digits' => ['M'],
            'unknown suffix' => ['128T'],
            'with spaces' => ['128 M'],
        ];
    }
}
