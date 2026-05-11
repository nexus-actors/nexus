<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Header;

use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(HeaderKeys::class)]
final class HeaderKeysTest extends TestCase
{
    #[Test]
    public function classIsFinal(): void
    {
        self::assertTrue(new ReflectionClass(HeaderKeys::class)->isFinal());
    }

    #[Test]
    public function definesSixConstants(): void
    {
        self::assertCount(6, new ReflectionClass(HeaderKeys::class)->getReflectionConstants());
    }

    #[Test]
    public function constantsHaveExpectedNamespacedValues(): void
    {
        self::assertSame('nexus.causation.depth', HeaderKeys::CAUSATION_DEPTH);
        self::assertSame('nexus.idempotency-key', HeaderKeys::IDEMPOTENCY_KEY);
        self::assertSame('nexus.principal', HeaderKeys::PRINCIPAL);
        self::assertSame('nexus.replay', HeaderKeys::REPLAY);
        self::assertSame('nexus.retry.attempt', HeaderKeys::RETRY_ATTEMPT);
        self::assertSame('nexus.retry.budget_remaining_ms', HeaderKeys::RETRY_BUDGET_REMAINING_MS);
    }

    #[Test]
    public function constantsAreTypedAsString(): void
    {
        foreach (new ReflectionClass(HeaderKeys::class)->getReflectionConstants() as $const) {
            $type = $const->getType();
            self::assertNotNull($type, $const->getName() . ' lacks a type declaration');
            self::assertSame('string', (string) $type);
        }
    }
}
