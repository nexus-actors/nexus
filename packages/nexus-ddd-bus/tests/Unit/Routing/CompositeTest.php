<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use Monadial\Nexus\Ddd\Bus\Routing\AttributeBased;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\ExplicitOnly;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Composite::class)]
#[CoversClass(RoutingResolution::class)]
final class CompositeTest extends TestCase
{
    #[Test]
    public function emptyStrategiesFallBackToDefault(): void
    {
        $composite = new Composite([], 'misc');

        $result = $composite->resolve(stdClass::class);

        self::assertTrue($result->isSome());

        $resolution = $result->getUnsafe();
        self::assertSame('misc', $resolution->busName);
        self::assertSame(Composite::class, $resolution->resolvedBy);
    }

    #[Test]
    public function firstSomeWins(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('first', RecordingStrategy::class))),
                new RecordingStrategy(Option::some(new RoutingResolution('second', RecordingStrategy::class))),
            ],
            'misc',
        );

        self::assertSame('first', $composite->resolve(stdClass::class)->getUnsafe()->busName);
    }

    #[Test]
    public function laterStrategyIsNotAskedAfterMatch(): void
    {
        $second = new RecordingStrategy(Option::some(new RoutingResolution('second', RecordingStrategy::class)));
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('first', RecordingStrategy::class))),
                $second,
            ],
            'misc',
        );

        $composite->resolve(stdClass::class);

        self::assertSame(0, $second->callCount);
    }

    #[Test]
    public function allNoneFallsBackToDefault(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::none()),
                new RecordingStrategy(Option::none()),
            ],
            'fallback-bus',
        );

        $resolution = $composite->resolve(stdClass::class)->getUnsafe();

        self::assertSame('fallback-bus', $resolution->busName);
        self::assertSame(Composite::class, $resolution->resolvedBy);
    }

    #[Test]
    public function withStrategyWithoutBeforeAppends(): void
    {
        $original = new Composite([new RecordingStrategy(Option::none())], 'misc');
        $appended = new RecordingStrategy(Option::some(new RoutingResolution('orders', RecordingStrategy::class)));

        $composite = $original->withStrategy($appended);

        self::assertSame('orders', $composite->resolve(stdClass::class)->getUnsafe()->busName);
    }

    #[Test]
    public function withStrategyBeforeInsertsAheadOfNamedClass(): void
    {
        $attributeBased = new AttributeBased();
        $explicitOnly = new ExplicitOnly()->explicit(stdClass::class, 'from-explicit');
        $composite = new Composite([$attributeBased, $explicitOnly], 'misc');

        $newStrategy = new RecordingStrategy(Option::some(new RoutingResolution('from-new', RecordingStrategy::class)));
        $reordered = $composite->withStrategy($newStrategy, before: ExplicitOnly::class);

        self::assertSame('from-new', $reordered->resolve(stdClass::class)->getUnsafe()->busName);
    }

    #[Test]
    public function withStrategyReturnsNewCompositeAndLeavesOriginalUnchanged(): void
    {
        $original = new Composite(
            [new RecordingStrategy(Option::none())],
            'misc',
        );
        $newStrategy = new RecordingStrategy(Option::some(new RoutingResolution('new', RecordingStrategy::class)));

        $modified = $original->withStrategy($newStrategy);

        self::assertNotSame($original, $modified);
        self::assertSame('misc', $original->resolve(stdClass::class)->getUnsafe()->busName);
        self::assertSame('new', $modified->resolve(stdClass::class)->getUnsafe()->busName);
    }

    #[Test]
    public function validatePassesWhenAllStrategiesAgree(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('orders', RecordingStrategy::class))),
                new SecondRecordingStrategy(
                    Option::some(new RoutingResolution('orders', SecondRecordingStrategy::class)),
                ),
            ],
            'misc',
        );

        $composite->validate([stdClass::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validatePassesWhenOnlyOneStrategyResolves(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('orders', RecordingStrategy::class))),
                new SecondRecordingStrategy(Option::none()),
            ],
            'misc',
        );

        $composite->validate([stdClass::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validateThrowsWhenStrategiesDisagree(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('orders', RecordingStrategy::class))),
                new SecondRecordingStrategy(
                    Option::some(new RoutingResolution('reporting', SecondRecordingStrategy::class)),
                ),
            ],
            'misc',
        );

        $this->expectException(DuplicateRoutingException::class);
        $this->expectExceptionMessage(stdClass::class);

        $composite->validate([stdClass::class]);
    }

    #[Test]
    public function validateThrowsOnFirstConflictWithoutVisitingRemaining(): void
    {
        $composite = new Composite(
            [
                new RecordingStrategy(Option::some(new RoutingResolution('a', RecordingStrategy::class))),
                new SecondRecordingStrategy(Option::some(new RoutingResolution('b', SecondRecordingStrategy::class))),
            ],
            'misc',
        );

        try {
            $composite->validate([stdClass::class, MessageInPattern::class]);
            self::fail('expected DuplicateRoutingException');
        } catch (DuplicateRoutingException $e) {
            self::assertStringContainsString(stdClass::class, $e->getMessage());
            self::assertStringNotContainsString(MessageInPattern::class, $e->getMessage());
        }
    }

    #[Test]
    public function validatePassesOverEmptyHandlerList(): void
    {
        $composite = new Composite([new AttributeBased()], 'misc');

        $composite->validate([]);

        $this->expectNotToPerformAssertions();
    }
}

final class RecordingStrategy implements RoutingStrategy
{
    public int $callCount = 0;

    /** @param Option<RoutingResolution> $answer */
    public function __construct(private readonly Option $answer) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        $this->callCount++;

        return $this->answer;
    }
}

final class SecondRecordingStrategy implements RoutingStrategy
{
    /** @param Option<RoutingResolution> $answer */
    public function __construct(private readonly Option $answer) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return $this->answer;
    }
}

final readonly class MessageInPattern {}
