<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Error;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageContextStack::class)]
final class MessageContextStackTryFinallyTest extends TestCase
{
    #[Test]
    public function popsEvenWhenCallbackThrowsErrorNotException(): void
    {
        $stack = MessageContextStack::default();
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-07T10:00:00+00:00');
            }
        };
        $ctx = new MessageContext(MessageMetadata::root($clock));

        try {
            $stack->within($ctx, static function (): void {
                throw new Error('boom-error');
            });
            self::fail('expected error');
        } catch (Error $expected) {
            self::assertSame('boom-error', $expected->getMessage());
        }

        self::assertTrue($stack->current()->isNone());
    }

    #[Test]
    public function nestedWithinCallsPopInLifoOrder(): void
    {
        $stack = MessageContextStack::default();
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-07T10:00:00+00:00');
            }
        };

        $outer = new MessageContext(MessageMetadata::root($clock));
        $inner = new MessageContext(MessageMetadata::root($clock));

        $observed = [];

        $stack->within($outer, static function () use ($stack, $inner, &$observed): void {
            $observed[] = $stack->current()->getOrCall(static fn() => null);

            $stack->within($inner, static function () use ($stack, &$observed): void {
                $observed[] = $stack->current()->getOrCall(static fn() => null);
            });

            $observed[] = $stack->current()->getOrCall(static fn() => null);
        });

        self::assertSame([$outer, $inner, $outer], $observed);
        self::assertTrue($stack->current()->isNone());
    }

}

