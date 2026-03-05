<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing\Tests\Unit;

use Monadial\Nexus\Symfony\Testing\MockActorRef;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class TestCmd {}

#[CoversClass(MockActorRef::class)]
final class MockActorRefTest extends TestCase
{
    #[Test]
    public function recordsTellCalls(): void
    {
        $ref = new MockActorRef();
        $msg = new TestCmd();

        $ref->tell($msg);

        self::assertCount(1, $ref->toldMessages());
        self::assertSame($msg, $ref->toldMessages()[0]);
    }

    #[Test]
    public function assertToldOncePassesWhenCalledOnce(): void
    {
        $ref = new MockActorRef();
        $ref->tell(new TestCmd());

        $ref->assertToldOnce(TestCmd::class);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertToldOnceFailsWhenNotCalled(): void
    {
        $ref = new MockActorRef();

        $this->expectException(AssertionFailedError::class);

        $ref->assertToldOnce(TestCmd::class);
    }
}
