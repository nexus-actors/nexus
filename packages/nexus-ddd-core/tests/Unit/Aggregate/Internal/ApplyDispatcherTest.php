<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplyDispatcher::class)]
final class ApplyDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchInvokesApplyMethodMatchingShortName(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $dispatcher->dispatch($aggregate, new SomeEvent('hello'));

        self::assertSame('hello', $aggregate->captured);
    }

    #[Test]
    public function missingApplyMethodThrows(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $this->expectException(ApplyMethodNotFoundException::class);
        $dispatcher->dispatch($aggregate, new UnhandledEvent());
    }

    #[Test]
    public function dispatchIsCachedAcrossInvocations(): void
    {
        $dispatcher = new ApplyDispatcher();
        $aggregate = new TargetAggregate();
        $dispatcher->dispatch($aggregate, new SomeEvent('a'));
        $dispatcher->dispatch($aggregate, new SomeEvent('b'));    // 2nd call uses cache

        self::assertSame('b', $aggregate->captured);
    }
}

final class TargetAggregate
{
    public string $captured = '';

    private function applySomeEvent(SomeEvent $e): void
    {
        $this->captured = $e->payload;
    }
}

final readonly class SomeEvent
{
    public function __construct(public string $payload) {}
}

final class UnhandledEvent {}
