<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Supervision;

use Error;
use Exception;
use LogicException;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\StrategyType;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(SupervisionStrategy::class)]
final class SupervisionStrategyTest extends TestCase
{
    #[Test]
    public function oneForOneCreatesOneForOneStrategy(): void
    {
        $strategy = SupervisionStrategy::oneForOne();

        self::assertSame(StrategyType::OneForOne, $strategy->type);
    }

    #[Test]
    public function allForOneCreatesAllForOneStrategy(): void
    {
        $strategy = SupervisionStrategy::allForOne();

        self::assertSame(StrategyType::AllForOne, $strategy->type);
    }

    #[Test]
    public function exponentialBackoffCreatesBackoffStrategy(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
        );

        self::assertSame(StrategyType::ExponentialBackoff, $strategy->type);
    }

    #[Test]
    public function oneForOneMaxRetriesAccessor(): void
    {
        $strategy = SupervisionStrategy::oneForOne(maxRetries: 5);

        self::assertSame(5, $strategy->maxRetries);
    }

    #[Test]
    public function allForOneMaxRetriesAccessor(): void
    {
        $strategy = SupervisionStrategy::allForOne(maxRetries: 7);

        self::assertSame(7, $strategy->maxRetries);
    }

    #[Test]
    public function exponentialBackoffMaxRetriesAccessor(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
            maxRetries: 10,
        );

        self::assertSame(10, $strategy->maxRetries);
    }

    #[Test]
    public function oneForOneWindowAccessor(): void
    {
        $window = Duration::seconds(120);
        $strategy = SupervisionStrategy::oneForOne(window: $window);

        self::assertTrue($window->equals($strategy->window));
    }

    #[Test]
    public function allForOneWindowAccessor(): void
    {
        $window = Duration::seconds(30);
        $strategy = SupervisionStrategy::allForOne(window: $window);

        self::assertTrue($window->equals($strategy->window));
    }

    #[Test]
    public function oneForOneDefaultWindowIsSixtySeconds(): void
    {
        $strategy = SupervisionStrategy::oneForOne();

        self::assertTrue(Duration::seconds(60)->equals($strategy->window));
    }

    #[Test]
    public function allForOneDefaultWindowIsSixtySeconds(): void
    {
        $strategy = SupervisionStrategy::allForOne();

        self::assertTrue(Duration::seconds(60)->equals($strategy->window));
    }

    #[Test]
    public function oneForOneDefaultMaxRetriesIsThree(): void
    {
        $strategy = SupervisionStrategy::oneForOne();

        self::assertSame(3, $strategy->maxRetries);
    }

    #[Test]
    public function allForOneDefaultMaxRetriesIsThree(): void
    {
        $strategy = SupervisionStrategy::allForOne();

        self::assertSame(3, $strategy->maxRetries);
    }

    #[Test]
    public function deciderInvocationReturnsCorrectDirective(): void
    {
        $decider = static function (Throwable $e): Directive {
            if ($e instanceof RuntimeException) {
                return Directive::Restart;
            }

            if ($e instanceof LogicException) {
                return Directive::Stop;
            }

            return Directive::Escalate;
        };

        $strategy = SupervisionStrategy::oneForOne(decider: $decider);

        self::assertSame(Directive::Restart, $strategy->decide(new RuntimeException('test')));
        self::assertSame(Directive::Stop, $strategy->decide(new LogicException('test')));
        self::assertSame(Directive::Escalate, $strategy->decide(new Exception('test')));
    }

    #[Test]
    public function defaultDeciderReturnsRestartForAllExceptions(): void
    {
        $strategy = SupervisionStrategy::oneForOne();

        self::assertSame(Directive::Restart, $strategy->decide(new RuntimeException('test')));
        self::assertSame(Directive::Restart, $strategy->decide(new LogicException('test')));
        self::assertSame(Directive::Restart, $strategy->decide(new Exception('test')));
        self::assertSame(Directive::Restart, $strategy->decide(new Error('test')));
    }

    #[Test]
    public function allForOneDefaultDeciderReturnsRestartForAllExceptions(): void
    {
        $strategy = SupervisionStrategy::allForOne();

        self::assertSame(Directive::Restart, $strategy->decide(new RuntimeException('test')));
        self::assertSame(Directive::Restart, $strategy->decide(new Exception('test')));
    }

    #[Test]
    public function exponentialBackoffDefaultDeciderReturnsRestartForAllExceptions(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
        );

        self::assertSame(Directive::Restart, $strategy->decide(new RuntimeException('test')));
        self::assertSame(Directive::Restart, $strategy->decide(new Exception('test')));
    }

    #[Test]
    public function exponentialBackoffStoresInitialBackoff(): void
    {
        $initial = Duration::millis(200);
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: $initial,
            maxBackoff: Duration::seconds(10),
        );

        self::assertTrue($initial->equals($strategy->initialBackoff));
    }

    #[Test]
    public function exponentialBackoffStoresMaxBackoff(): void
    {
        $max = Duration::seconds(30);
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: $max,
        );

        self::assertTrue($max->equals($strategy->maxBackoff));
    }

    #[Test]
    public function exponentialBackoffDefaultMultiplierIsTwo(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
        );

        self::assertSame(2.0, $strategy->multiplier);
    }

    #[Test]
    public function exponentialBackoffCustomMultiplier(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
            multiplier: 1.5,
        );

        self::assertSame(1.5, $strategy->multiplier);
    }

    #[Test]
    public function exponentialBackoffDefaultMaxRetriesIsThree(): void
    {
        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
        );

        self::assertSame(3, $strategy->maxRetries);
    }

    #[Test]
    public function exponentialBackoffWithCustomDecider(): void
    {
        $decider = static fn(Throwable $_): Directive => Directive::Stop;

        $strategy = SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff: Duration::seconds(10),
            decider: $decider,
        );

        self::assertSame(Directive::Stop, $strategy->decide(new RuntimeException('test')));
    }

    #[Test]
    public function oneForOneWithCustomDecider(): void
    {
        $decider = static fn(Throwable $_): Directive => Directive::Resume;

        $strategy = SupervisionStrategy::oneForOne(decider: $decider);

        self::assertSame(Directive::Resume, $strategy->decide(new RuntimeException('test')));
    }

    #[Test]
    public function allForOneWithCustomDecider(): void
    {
        $decider = static fn(Throwable $_): Directive => Directive::Escalate;

        $strategy = SupervisionStrategy::allForOne(decider: $decider);

        self::assertSame(Directive::Escalate, $strategy->decide(new RuntimeException('test')));
    }
}
