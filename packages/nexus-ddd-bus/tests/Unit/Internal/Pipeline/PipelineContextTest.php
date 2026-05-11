<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Internal\Pipeline;

use Error;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use Monadial\Nexus\Ddd\Bus\Internal\Pipeline\PipelineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(PipelineContext::class)]
final class PipelineContextTest extends TestCase
{
    #[Test]
    public function defaultsAreNoneReservationAndZeroDepthAndZeroRetry(): void
    {
        $ctx = new PipelineContext();

        self::assertTrue($ctx->idempotencyReservation->isNone());
        self::assertSame(0, $ctx->causationDepth);
        self::assertSame(0, $ctx->retryAttempt);
    }

    #[Test]
    public function rememberReservationStoresOptionSome(): void
    {
        $ctx = new PipelineContext();
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), 'cpk');

        $ctx->rememberReservation($reservation);

        self::assertTrue($ctx->idempotencyReservation->isSome());
        $stored = $ctx->idempotencyReservation->getOrElse(
            new InMemoryReservation(stdClass::class, new IdempotencyKey('x'), 'miss'),
        );
        self::assertSame($reservation, $stored);
        self::assertInstanceOf(IdempotencyReservation::class, $stored);
    }

    #[Test]
    public function setCausationDepthStoresValue(): void
    {
        $ctx = new PipelineContext();

        $ctx->setCausationDepth(7);

        self::assertSame(7, $ctx->causationDepth);
    }

    #[Test]
    public function incrementRetryAttemptIncrementsByOne(): void
    {
        $ctx = new PipelineContext();

        $ctx->incrementRetryAttempt();
        self::assertSame(1, $ctx->retryAttempt);

        $ctx->incrementRetryAttempt();
        $ctx->incrementRetryAttempt();
        self::assertSame(3, $ctx->retryAttempt);
    }

    #[Test]
    public function causationDepthCannotBeMutatedExternally(): void
    {
        $ctx = new PipelineContext();

        $this->expectException(Error::class);

        /** @psalm-suppress NoValue */
        $ctx->causationDepth = 5;
    }

    #[Test]
    public function retryAttemptCannotBeMutatedExternally(): void
    {
        $ctx = new PipelineContext();

        $this->expectException(Error::class);

        /** @psalm-suppress NoValue */
        $ctx->retryAttempt = 99;
    }

    #[Test]
    public function idempotencyReservationCannotBeMutatedExternally(): void
    {
        $ctx = new PipelineContext();

        $this->expectException(Error::class);

        /** @psalm-suppress NoValue */
        $ctx->idempotencyReservation = $ctx->idempotencyReservation;
    }
}
