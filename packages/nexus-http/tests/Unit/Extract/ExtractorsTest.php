<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Extract;

use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Extract\LongNumber;
use Monadial\Nexus\Http\Extract\Remaining;
use Monadial\Nexus\Http\Extract\StringSegment;
use Monadial\Nexus\Http\Extract\UlidSegment;
use Monadial\Nexus\Http\Extract\UuidSegment;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IntNumber::class)]
#[CoversClass(LongNumber::class)]
#[CoversClass(Remaining::class)]
#[CoversClass(StringSegment::class)]
#[CoversClass(UlidSegment::class)]
#[CoversClass(UuidSegment::class)]
final class ExtractorsTest extends TestCase
{
    #[Test]
    public function int_number_parses_positive_integers(): void
    {
        self::assertSame(42, (new IntNumber())->fromSegment('42'));
    }

    #[Test]
    public function int_number_rejects_non_integer(): void
    {
        $this->expectException(ExtractorRejection::class);

        (new IntNumber())->fromSegment('abc');
    }

    #[Test]
    public function long_number_parses_large_integers(): void
    {
        self::assertSame(9_000_000_000, (new LongNumber())->fromSegment('9000000000'));
    }

    #[Test]
    public function string_segment_returns_value(): void
    {
        self::assertSame('hello', (new StringSegment())->fromSegment('hello'));
    }

    #[Test]
    public function ulid_segment_returns_ulid(): void
    {
        $ulid = (new UlidSegment())->fromSegment('01HW00000000000000000000ZZ');

        self::assertInstanceOf(Ulid::class, $ulid);
    }

    #[Test]
    public function uuid_segment_returns_uuid(): void
    {
        $uuid = (new UuidSegment())->fromSegment('550e8400-e29b-41d4-a716-446655440000');

        self::assertInstanceOf(Uuid::class, $uuid);
    }

    #[Test]
    public function ulid_segment_rejects_invalid_value(): void
    {
        $this->expectException(ExtractorRejection::class);

        (new UlidSegment())->fromSegment('not-a-ulid');
    }

    #[Test]
    public function remaining_returns_value(): void
    {
        self::assertSame('a/b/c', (new Remaining())->fromSegment('a/b/c'));
    }
}
