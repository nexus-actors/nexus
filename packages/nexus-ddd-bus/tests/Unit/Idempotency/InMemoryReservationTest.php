<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemoryReservation::class)]
final class InMemoryReservationTest extends TestCase
{
    #[Test]
    public function implementsIdempotencyReservation(): void
    {
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), 'composite');

        self::assertInstanceOf(IdempotencyReservation::class, $reservation);
    }

    #[Test]
    public function accessorsReturnConstructorArguments(): void
    {
        $key = new IdempotencyKey('k-1');
        $reservation = new InMemoryReservation(stdClass::class, $key, 'stdClass::k-1');

        self::assertSame(stdClass::class, $reservation->handlerClass());
        self::assertSame($key, $reservation->idempotencyKey());
        self::assertSame('stdClass::k-1', $reservation->compositeKey);
    }
}
