<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Context\StaticStackContextStorage;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(MessageContextStack::class)]
final class MessageContextStackTest extends TestCase
{
    #[Test]
    public function defaultFactoryWiresStaticStackContextStorage(): void
    {
        self::assertInstanceOf(StaticStackContextStorage::class, MessageContextStack::default()->storage());
    }

    #[Test]
    public function constructorInjectsTheGivenStorage(): void
    {
        $storage = new StaticStackContextStorage();
        $stack = new MessageContextStack($storage);
        self::assertSame($storage, $stack->storage());
    }

    #[Test]
    public function currentReturnsNoneOnFreshStack(): void
    {
        self::assertTrue(MessageContextStack::default()->current()->isNone());
    }

    #[Test]
    public function pushExposesContextThenPopRestoresEmpty(): void
    {
        $stack = MessageContextStack::default();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock()));

        $stack->push($ctx);
        self::assertSame($ctx, $stack->current()->getOrElse($fallback));

        $stack->pop();
        self::assertTrue($stack->current()->isNone());
    }

    #[Test]
    public function withinPushesAndPopsInTryFinally(): void
    {
        $stack = MessageContextStack::default();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $observed = null;

        $result = $stack->within($ctx, static function () use ($stack, &$observed): string {
            $observed = $stack->current()->getOrCall(static fn() => null);

            return 'returned-value';
        });

        self::assertSame($ctx, $observed);
        self::assertSame('returned-value', $result);
        self::assertTrue($stack->current()->isNone());
    }

    #[Test]
    public function withinPopsEvenWhenCallbackThrows(): void
    {
        $stack = MessageContextStack::default();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock()));

        try {
            $stack->within($ctx, static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (RuntimeException $expected) {
            self::assertSame('boom', $expected->getMessage());
        }

        self::assertTrue($stack->current()->isNone());
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };
    }
}
