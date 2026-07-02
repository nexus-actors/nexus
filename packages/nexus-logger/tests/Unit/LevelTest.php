<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Logger\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(Level::class)]
final class LevelTest extends TestCase
{
    #[Test]
    public function from_psr3_resolves_all_eight_canonical_levels(): void
    {
        self::assertSame(Level::Debug, Level::fromPsr3(LogLevel::DEBUG));
        self::assertSame(Level::Info, Level::fromPsr3(LogLevel::INFO));
        self::assertSame(Level::Notice, Level::fromPsr3(LogLevel::NOTICE));
        self::assertSame(Level::Warning, Level::fromPsr3(LogLevel::WARNING));
        self::assertSame(Level::Error, Level::fromPsr3(LogLevel::ERROR));
        self::assertSame(Level::Critical, Level::fromPsr3(LogLevel::CRITICAL));
        self::assertSame(Level::Alert, Level::fromPsr3(LogLevel::ALERT));
        self::assertSame(Level::Emergency, Level::fromPsr3(LogLevel::EMERGENCY));
    }

    #[Test]
    public function from_psr3_rejects_unknown_level(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Level::fromPsr3('verbose');
    }

    #[Test]
    public function round_trip_psr3_strings_are_stable(): void
    {
        foreach (Level::cases() as $case) {
            self::assertSame($case, Level::fromPsr3($case->toPsr3()));
        }
    }

    #[Test]
    public function severity_ordering_matches_rfc_5424(): void
    {
        // Emergency is highest severity, Debug is lowest.
        self::assertTrue(Level::Emergency->isAtLeast(Level::Debug));
        self::assertTrue(Level::Warning->isAtLeast(Level::Info));
        self::assertFalse(Level::Info->isAtLeast(Level::Warning));
        self::assertTrue(Level::Debug->isAtLeast(Level::Debug));
    }
}
