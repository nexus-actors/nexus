<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class IdempotencyStoreInterfaceTest extends TestCase
{
    #[Test]
    public function tryReserveAcceptsHandlerClassAndKeyAndReturnsOption(): void
    {
        $reflection = new ReflectionClass(IdempotencyStore::class);
        $method = $reflection->getMethod('tryReserve');
        $params = $method->getParameters();

        self::assertCount(2, $params);

        self::assertSame('handlerClass', $params[0]->getName());
        $handlerType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $handlerType);
        self::assertSame('string', $handlerType->getName());

        self::assertSame('key', $params[1]->getName());
        $keyType = $params[1]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $keyType);
        self::assertSame(IdempotencyKey::class, $keyType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Option::class, $returnType->getName());
    }

    #[Test]
    public function markCompletedAcceptsTokenAndReturnsVoid(): void
    {
        $reflection = new ReflectionClass(IdempotencyStore::class);
        $method = $reflection->getMethod('markCompleted');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('token', $params[0]->getName());
        $tokenType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $tokenType);
        self::assertSame(IdempotencyReservation::class, $tokenType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function releaseAcceptsTokenAndReturnsVoid(): void
    {
        $reflection = new ReflectionClass(IdempotencyStore::class);
        $method = $reflection->getMethod('release');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('token', $params[0]->getName());
        $tokenType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $tokenType);
        self::assertSame(IdempotencyReservation::class, $tokenType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function ttlReturnsFiniteDuration(): void
    {
        $reflection = new ReflectionClass(IdempotencyStore::class);
        $method = $reflection->getMethod('ttl');

        self::assertCount(0, $method->getParameters());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(FiniteDuration::class, $returnType->getName());
    }

    #[Test]
    public function interfaceExposesExactlyFourMethods(): void
    {
        $reflection = new ReflectionClass(IdempotencyStore::class);
        $methods = array_map(static fn($m): string => $m->getName(), $reflection->getMethods());
        sort($methods);

        self::assertSame(['markCompleted', 'release', 'tryReserve', 'ttl'], $methods);
    }
}
