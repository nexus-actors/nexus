<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Header;

use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Headers::class)]
final class HeadersTest extends TestCase
{
    #[Test]
    public function emptyProducesNoEntries(): void
    {
        $headers = Headers::empty();

        self::assertSame([], $headers->values);
    }

    #[Test]
    public function ofWrapsTheGivenValues(): void
    {
        $headers = Headers::of(['nexus.idempotency-key' => 'k1', 'nexus.principal-id' => 'u-1']);

        self::assertSame('k1', $headers->values['nexus.idempotency-key']);
        self::assertSame('u-1', $headers->values['nexus.principal-id']);
    }

    #[Test]
    public function getReturnsSomeWhenKeyPresent(): void
    {
        $headers = Headers::of(['nexus.idempotency-key' => 'k1']);

        $value = $headers->get('nexus.idempotency-key');

        self::assertTrue($value->isSome());
        self::assertSame('k1', $value->get());
    }

    #[Test]
    public function getReturnsNoneWhenKeyAbsent(): void
    {
        $headers = Headers::empty();

        self::assertTrue($headers->get('nexus.missing')->isNone());
    }

    #[Test]
    public function hasReturnsTrueWhenKeyPresent(): void
    {
        $headers = Headers::of(['nexus.principal-id' => 'u-1']);

        self::assertTrue($headers->has('nexus.principal-id'));
        self::assertFalse($headers->has('nexus.missing'));
    }

    #[Test]
    public function withReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $original = Headers::empty();

        $extended = $original->with('nexus.idempotency-key', 'k1');

        self::assertNotSame($original, $extended);
        self::assertSame([], $original->values);
        self::assertSame('k1', $extended->values['nexus.idempotency-key']);
    }

    #[Test]
    public function withSupportsAllScalarTypes(): void
    {
        $headers = Headers::empty()
            ->with('s', 'string')
            ->with('i', 42)
            ->with('f', 3.14)
            ->with('b', true);

        self::assertSame('string', $headers->get('s')->get());
        self::assertSame(42, $headers->get('i')->get());
        self::assertSame(3.14, $headers->get('f')->get());
        self::assertTrue($headers->get('b')->get());
    }

    #[Test]
    public function withReplacesExistingKey(): void
    {
        $headers = Headers::of(['nexus.principal-id' => 'u-1']);

        $updated = $headers->with('nexus.principal-id', 'u-2');

        self::assertSame('u-2', $updated->get('nexus.principal-id')->get());
    }

    #[Test]
    public function mergeOverlaysOtherOverThis(): void
    {
        $a = Headers::of(['nexus.principal-id' => 'u-1', 'nexus.retry.budget_remaining_ms' => 1000]);
        $b = Headers::of(['nexus.idempotency-key' => 'k1', 'nexus.principal-id' => 'u-2']);

        $merged = $a->merge($b);

        self::assertSame('u-2', $merged->get('nexus.principal-id')->get());
        self::assertSame('k1', $merged->get('nexus.idempotency-key')->get());
        self::assertSame(1000, $merged->get('nexus.retry.budget_remaining_ms')->get());
    }

    #[Test]
    public function mergeReturnsNewInstance(): void
    {
        $a = Headers::of(['x' => 1]);
        $b = Headers::of(['y' => 2]);

        $merged = $a->merge($b);

        self::assertNotSame($a, $merged);
        self::assertNotSame($b, $merged);
        self::assertSame(['x' => 1], $a->values);
        self::assertSame(['y' => 2], $b->values);
    }

    #[Test]
    public function equalityIgnoresInsertionOrderForArrayContents(): void
    {
        $a = Headers::of(['a' => 1, 'b' => 2]);
        $b = Headers::of(['b' => 2, 'a' => 1]);

        self::assertSame($a->get('a')->get(), $b->get('a')->get());
        self::assertSame($a->get('b')->get(), $b->get('b')->get());
        self::assertSame(2, count($a->values));
        self::assertSame(2, count($b->values));
    }
}
