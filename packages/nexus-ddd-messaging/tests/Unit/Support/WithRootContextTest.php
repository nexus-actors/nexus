<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\WithRootContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(WithRootContext::class)]
final class WithRootContextTest extends TestCase
{
    #[Test]
    public function pushesRootContextDuringCallbackAndPopsAfter(): void
    {
        $stack = MessageContextStack::default();
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
        $helper = new WithRootContext($stack, $clock);

        $observed = Option::none();
        $result = $helper->run(static function () use ($stack, &$observed): string {
            $observed = $stack->current();

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertTrue($observed->isSome());
        self::assertInstanceOf(MessageContext::class, $observed->get());
        self::assertTrue($stack->current()->isNone());
    }

    #[Test]
    public function defaultFactoryWiresFreshStackAndSystemClock(): void
    {
        $helper = WithRootContext::default();

        $result = $helper->run(static fn(): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function exposesStackForTestAssertion(): void
    {
        $stack = MessageContextStack::default();
        $helper = new WithRootContext($stack, new SystemClock());
        self::assertSame($stack, $helper->stack());
    }
}
