<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class IdempotencyReservationInterfaceTest extends TestCase
{
    #[Test]
    public function handlerClassReturnsString(): void
    {
        $reflection = new ReflectionClass(IdempotencyReservation::class);
        $method = $reflection->getMethod('handlerClass');

        self::assertCount(0, $method->getParameters());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
    }

    #[Test]
    public function idempotencyKeyReturnsIdempotencyKey(): void
    {
        $reflection = new ReflectionClass(IdempotencyReservation::class);
        $method = $reflection->getMethod('idempotencyKey');

        self::assertCount(0, $method->getParameters());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(IdempotencyKey::class, $returnType->getName());
    }

    #[Test]
    public function interfaceExposesOnlyHandlerClassAndIdempotencyKey(): void
    {
        $reflection = new ReflectionClass(IdempotencyReservation::class);
        $methods = array_map(static fn($m): string => $m->getName(), $reflection->getMethods());
        sort($methods);

        self::assertSame(['handlerClass', 'idempotencyKey'], $methods);
    }
}
