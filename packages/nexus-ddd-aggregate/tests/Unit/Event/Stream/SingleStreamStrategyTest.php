<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Stream;

use Monadial\Nexus\Ddd\Aggregate\Event\Stream\SingleStreamStrategy;
use Monadial\Nexus\Ddd\Aggregate\Event\Stream\StreamName;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SingleStreamStrategy::class)]
final class SingleStreamStrategyTest extends TestCase
{
    #[Test]
    public function streamForReturnsDddEventsRegardlessOfClassOrId(): void
    {
        $strategy = new SingleStreamStrategy();
        $id = $this->stubIdentifier('order-1');
        self::assertTrue(
            $strategy->streamFor('App\\Order', $id)->equals(new StreamName('ddd_events')),
        );
        self::assertTrue(
            $strategy->streamFor('App\\Customer', $id)->equals(new StreamName('ddd_events')),
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
