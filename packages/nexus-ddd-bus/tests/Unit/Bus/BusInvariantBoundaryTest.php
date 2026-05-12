<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Bus\BusInvariantBoundary;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(BusInvariantBoundary::class)]
final class BusInvariantBoundaryTest extends TestCase
{
    #[Test]
    public function returnsEitherRightOnSuccess(): void
    {
        $result = BusInvariantBoundary::tryRun(static fn(): string => 'ok');

        self::assertInstanceOf(Either::class, $result);
        self::assertTrue($result->isRight());
        self::assertSame('ok', $result->get());
    }

    #[Test]
    public function liftsOrdinaryThrowableToEitherLeft(): void
    {
        $failure = new RuntimeException('boom');

        $result = BusInvariantBoundary::tryRun(static fn() => throw $failure);

        self::assertInstanceOf(Either::class, $result);
        self::assertTrue($result->isLeft());
        self::assertSame($failure, $result->get());
    }

    #[Test]
    public function propagatesBusInvariantException(): void
    {
        $failure = new BusInvariantBoundaryFixtureInvariant('config broken');

        try {
            BusInvariantBoundary::tryRun(static fn() => throw $failure);
            self::fail('expected propagation');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }
    }
}

final class BusInvariantBoundaryFixtureInvariant extends RuntimeException implements BusInvariantException {}
