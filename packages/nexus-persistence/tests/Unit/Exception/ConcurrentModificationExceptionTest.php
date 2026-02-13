<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Exception;

use Monadial\Nexus\Persistence\Exception\ConcurrentModificationException;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConcurrentModificationException::class)]
final class ConcurrentModificationExceptionTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $persistenceId = PersistenceId::of('Account', 'acc-1');
        $exception = new ConcurrentModificationException(
            $persistenceId,
            5,
            'Entity was modified concurrently',
        );

        self::assertSame($persistenceId, $exception->persistenceId);
        self::assertSame(5, $exception->expectedVersion);
        self::assertSame('Entity was modified concurrently', $exception->getMessage());
    }

    #[Test]
    public function previousExceptionIsPreserved(): void
    {
        $previous = new \RuntimeException('underlying cause');
        $exception = new ConcurrentModificationException(
            PersistenceId::of('Account', 'acc-1'),
            3,
            'Concurrent modification detected',
            $previous,
        );

        self::assertSame($previous, $exception->getPrevious());
    }
}
