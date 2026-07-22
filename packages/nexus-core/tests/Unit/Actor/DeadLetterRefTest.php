<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Event\MessageDeadLettered;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;

#[CoversClass(DeadLetterRef::class)]
final class DeadLetterRefTest extends TestCase
{
    #[Test]
    public function capturedReturnsEmptyInitially(): void
    {
        $ref = new DeadLetterRef();

        self::assertSame([], $ref->captured());
    }

    #[Test]
    public function tellCapturesMessage(): void
    {
        $ref = new DeadLetterRef();
        $message = new stdClass();
        $message->text = 'hello';

        $ref->tell($message);

        self::assertCount(1, $ref->captured());
        self::assertSame($message, $ref->captured()[0]);
    }

    #[Test]
    public function tellCapturesMultipleMessages(): void
    {
        $ref = new DeadLetterRef();

        $msg1 = new stdClass();
        $msg1->id = 1;
        $msg2 = new stdClass();
        $msg2->id = 2;
        $msg3 = new stdClass();
        $msg3->id = 3;

        $ref->tell($msg1);
        $ref->tell($msg2);
        $ref->tell($msg3);

        self::assertCount(3, $ref->captured());
        self::assertSame($msg1, $ref->captured()[0]);
        self::assertSame($msg2, $ref->captured()[1]);
        self::assertSame($msg3, $ref->captured()[2]);
    }

    #[Test]
    public function boundsRetainedSamplesToMaxKeepingNewest(): void
    {
        $ref = new DeadLetterRef(maxSamples: 2);

        $msg1 = new stdClass();
        $msg2 = new stdClass();
        $msg3 = new stdClass();

        $ref->tell($msg1);
        $ref->tell($msg2);
        $ref->tell($msg3);

        // Oldest evicted; only the two newest are retained, re-indexed as a list.
        self::assertCount(2, $ref->captured());
        self::assertSame($msg2, $ref->captured()[0]);
        self::assertSame($msg3, $ref->captured()[1]);
    }

    #[Test]
    public function totalCountIsMonotonicAcrossEviction(): void
    {
        $ref = new DeadLetterRef(maxSamples: 2);

        self::assertSame(0, $ref->total());

        for ($i = 0; $i < 100; $i++) {
            $ref->tell(new stdClass());
        }

        // Samples stay bounded (stable memory) while the total keeps counting.
        self::assertSame(100, $ref->total());
        self::assertCount(2, $ref->captured());
    }

    #[Test]
    public function dispatchesMessageDeadLetteredEventOnCapture(): void
    {
        $captured = [];
        $events = new class ($captured) implements EventDispatcherInterface {
            /** @param list<object> $captured */
            public function __construct(private array &$captured) {}

            public function dispatch(object $event): object
            {
                $this->captured[] = $event;

                return $event;
            }
        };

        $ref = new DeadLetterRef(events: $events);
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $captured);
        self::assertInstanceOf(MessageDeadLettered::class, $captured[0]);
        self::assertSame($message, $captured[0]->message);
    }

    #[Test]
    public function rejectsNonPositiveMaxSamples(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeadLetterRef(maxSamples: 0);
    }

    #[Test]
    public function askThrowsAskTimeoutException(): void
    {
        $ref = new DeadLetterRef();
        $timeout = Duration::seconds(5);

        try {
            (void) $ref->ask(new stdClass(), $timeout);
            self::fail('Expected AskTimeoutException was not thrown');
        } catch (AskTimeoutException $e) {
            self::assertTrue($ref->path()->equals($e->target));
            self::assertTrue($timeout->equals($e->timeout));
        }
    }

    #[Test]
    public function isAliveReturnsFalse(): void
    {
        $ref = new DeadLetterRef();

        self::assertFalse($ref->isAlive());
    }

    #[Test]
    public function pathReturnsDeadLettersPath(): void
    {
        $ref = new DeadLetterRef();

        $expectedPath = ActorPath::fromString('/system/deadLetters');

        self::assertTrue($expectedPath->equals($ref->path()));
        self::assertSame('/system/deadLetters', (string) $ref->path());
    }
}
