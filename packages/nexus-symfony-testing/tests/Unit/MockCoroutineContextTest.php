<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing\Tests\Unit;

use ArrayObject;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCoroutineContext::class)]
final class MockCoroutineContextTest extends TestCase
{
    #[Test]
    public function currentReturnsSameObjectOnRepeatedCalls(): void
    {
        $context = new MockCoroutineContext();

        self::assertSame($context->current(), $context->current());
    }

    #[Test]
    public function currentReturnsArrayObject(): void
    {
        $context = new MockCoroutineContext();

        self::assertInstanceOf(ArrayObject::class, $context->current());
    }

    #[Test]
    public function differentInstancesReturnIndependentContexts(): void
    {
        $a = new MockCoroutineContext();
        $b = new MockCoroutineContext();

        $a->current()['key'] = 'value-a';

        self::assertArrayNotHasKey('key', (array) $b->current());
    }
}
