<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\Associable;
use Monadial\Nexus\Ddd\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(Associable::class)]
final class AssociableTest extends TestCase
{
    #[Test]
    public function ulidValueImplementingAssociableReturnsAssociationValue(): void
    {
        $id = new readonly class ((string) new Ulid()) extends UlidValue implements Associable {
            public function associationValue(): string
            {
                return (string) $this;
            }
        };

        self::assertSame((string) $id, $id->associationValue());
    }
}
