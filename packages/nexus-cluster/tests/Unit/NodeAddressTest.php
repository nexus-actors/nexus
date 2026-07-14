<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeAddress::class)]
final class NodeAddressTest extends TestCase
{
    #[Test]
    public function toPathPrefixIsALosslessJoin(): void
    {
        $address = new NodeAddress('prod', 'eu-west', 'payments.api', 'node_1');

        self::assertSame('/cluster/prod/eu-west/payments.api/node_1', $address->toPathPrefix());
    }

    #[Test]
    public function distinctIdentitiesNeverAliasToTheSamePrefix(): void
    {
        // The old lossy normalize() collapsed both of these to the same prefix — a collision hazard
        // in a membership protocol. They must now be rejected (invalid) rather than silently merged.
        $this->expectException(InvalidArgumentException::class);

        new NodeAddress('a b', 'dc', 'app', 'node');
    }

    #[Test]
    #[DataProvider('invalidSegments')]
    public function rejectsNonUrlSafeSegments(string $bad): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NodeAddress($bad, 'dc', 'app', 'node');
    }

    #[Test]
    public function acceptsUrlSafeSegments(): void
    {
        $address = new NodeAddress('cluster-1', 'dc_2', 'app.v3', 'node-4');

        self::assertSame('cluster-1', $address->cluster);
        self::assertSame('/cluster/cluster-1/dc_2/app.v3/node-4', $address->toPathPrefix());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSegments(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'slash' => ['a/b'];
        yield 'colon' => ['host:1'];
        yield 'unicode' => ['nöde'];
    }
}
