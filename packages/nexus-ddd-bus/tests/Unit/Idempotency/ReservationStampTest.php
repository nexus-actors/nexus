<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\ReservationStamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ReservationStamp::class)]
final class ReservationStampTest extends TestCase
{
    #[Test]
    public function exposesReservationViaPublicProperty(): void
    {
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), stdClass::class . '::k');

        $stamp = new ReservationStamp($reservation);

        self::assertSame($reservation, $stamp->reservation);
    }

    #[Test]
    public function implementsStampMarker(): void
    {
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), stdClass::class . '::k');

        self::assertInstanceOf(Stamp::class, new ReservationStamp($reservation));
    }
}
