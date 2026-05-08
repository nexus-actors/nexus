<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Error;
use Monadial\Nexus\Ddd\Messaging\Context\CurrentMessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(CurrentMessageContext::class)]
final class CurrentMessageContextTryFinallyTest extends TestCase
{
    private NodeId $nodeId;

    protected function setUp(): void
    {
        $this->nodeId = NodeId::generate();
    }

    protected function tearDown(): void
    {
        CurrentMessageContext::resetStorage();
    }

    #[Test]
    public function popsEvenWhenCallbackThrowsErrorNotException(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-07T10:00:00+00:00');
            }
        };

        $ctx = new MessageContext(MessageMetadata::root($clock, $this->nodeId));

        try {
            CurrentMessageContext::within($ctx, static function (): void {
                throw new Error('boom-error');
            });
            self::fail('expected error');
        } catch (Error $expected) {
            self::assertSame('boom-error', $expected->getMessage());
        }

        self::assertTrue(CurrentMessageContext::current()->isNone());
    }

    #[Test]
    public function nestedWithinCallsPopInLifoOrder(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-07T10:00:00+00:00');
            }
        };

        $outer = new MessageContext(MessageMetadata::root($clock, $this->nodeId));
        $inner = new MessageContext(MessageMetadata::root($clock, $this->nodeId));

        $observed = [];

        CurrentMessageContext::within($outer, static function () use ($inner, &$observed): void {
            $observed[] = CurrentMessageContext::current()->getOrCall(static fn() => null);

            CurrentMessageContext::within($inner, static function () use (&$observed): void {
                $observed[] = CurrentMessageContext::current()->getOrCall(static fn() => null);
            });

            $observed[] = CurrentMessageContext::current()->getOrCall(static fn() => null);
        });

        self::assertSame([$outer, $inner, $outer], $observed);
        self::assertTrue(CurrentMessageContext::current()->isNone());
    }
}
