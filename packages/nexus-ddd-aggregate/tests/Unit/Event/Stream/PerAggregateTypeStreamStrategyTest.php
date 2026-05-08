<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Stream;

use Monadial\Nexus\Ddd\Aggregate\Event\Stream\PerAggregateTypeStreamStrategy;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerAggregateTypeStreamStrategy::class)]
final class PerAggregateTypeStreamStrategyTest extends TestCase
{
    #[Test]
    public function streamForReturnsAggregateTypeSnakeCase(): void
    {
        $strategy = new PerAggregateTypeStreamStrategy();
        $id = $this->stubIdentifier('order-1');
        self::assertSame(
            'ddd_events_order',
            $strategy->streamFor('App\\Order', $id)->value(),
        );
        self::assertSame(
            'ddd_events_customer_account',
            $strategy->streamFor('App\\CustomerAccount', $id)->value(),
        );
    }

    #[Test]
    public function streamForUsesShortNameNotFqcn(): void
    {
        $strategy = new PerAggregateTypeStreamStrategy();
        $id = $this->stubIdentifier('order-1');
        self::assertSame(
            $strategy->streamFor('App\\Order', $id)->value(),
            $strategy->streamFor('Different\\Namespace\\Order', $id)->value(),
        );
    }

    private function stubIdentifier(string $value): Identifier
    {
        return new class ($value) implements Identifier {
            public function __construct(private readonly string $value)
            {
            }

            public function value(): string
            {
                return $this->value;
            }

            public function equals(Identifier $other): bool
            {
                return $this->value === $other->value();
            }

            public static function fromString(string $value): static
            {
                return new static($value);
            }
        };
    }
}
