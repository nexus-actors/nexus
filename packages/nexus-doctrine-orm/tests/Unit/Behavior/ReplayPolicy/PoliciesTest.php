<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\OnDemand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(FailIfMissing::class)]
#[CoversClass(CreateIfMissing::class)]
#[CoversClass(OnDemand::class)]
final class PoliciesTest extends TestCase
{
    #[Test]
    public function failIfMissingThrowsWhenAbsent(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        (new FailIfMissing())->resolve($em, stdClass::class, 'k');
    }

    #[Test]
    public function failIfMissingReturnsEntityWhenPresent(): void
    {
        $obj = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($obj);

        self::assertSame($obj, (new FailIfMissing())->resolve($em, stdClass::class, 'k'));
    }

    #[Test]
    public function createIfMissingUsesFactoryAndPersists(): void
    {
        $factoryCalls = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::once())->method('persist');

        $policy = new CreateIfMissing(static function (mixed $id) use (&$factoryCalls): object {
            $factoryCalls++;
            $o = new stdClass();
            $o->id = $id;

            return $o;
        });

        $resolved = $policy->resolve($em, stdClass::class, 'k');
        self::assertSame(1, $factoryCalls);
        self::assertSame('k', $resolved->id);
    }

    #[Test]
    public function createIfMissingReturnsExistingWhenPresent(): void
    {
        $existing = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($existing);
        $em->expects(self::never())->method('persist');

        /** @psalm-suppress UnusedClosureParam */
        $policy = new CreateIfMissing(static fn(mixed $id): object => new stdClass());

        self::assertSame($existing, $policy->resolve($em, stdClass::class, 'k'));
    }

    #[Test]
    public function onDemandReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        self::assertNull((new OnDemand())->resolve($em, stdClass::class, 'k'));
    }
}
