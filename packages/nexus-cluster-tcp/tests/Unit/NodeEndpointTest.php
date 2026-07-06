<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeEndpoint::class)]
final class NodeEndpointTest extends TestCase
{
    #[Test]
    public function fromStringParsesHostAndPort(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:9000');

        self::assertSame('localhost', $endpoint->host);
        self::assertSame(9000, $endpoint->port);
    }

    #[Test]
    public function fromStringWithIpv4Address(): void
    {
        $endpoint = NodeEndpoint::fromString('192.168.1.100:7355');

        self::assertSame('192.168.1.100', $endpoint->host);
        self::assertSame(7355, $endpoint->port);
    }

    #[Test]
    public function fromStringPortZeroIsValid(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:0');

        self::assertSame(0, $endpoint->port);
    }

    #[Test]
    public function fromStringMaxPortIsValid(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:65535');

        self::assertSame(65535, $endpoint->port);
    }

    #[Test]
    public function fromStringRejectsPortAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port');

        NodeEndpoint::fromString('localhost:65536');
    }

    #[Test]
    public function fromStringRejectsNegativePort(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NodeEndpoint::fromString('localhost:-1');
    }

    #[Test]
    public function fromStringRejectsMissingColon(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NodeEndpoint::fromString('localhost9000');
    }

    #[Test]
    public function fromStringRejectsEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NodeEndpoint::fromString(':9000');
    }

    #[Test]
    public function toStringFormat(): void
    {
        $endpoint = new NodeEndpoint('localhost', 9000);

        self::assertSame('localhost:9000', (string) $endpoint);
    }

    #[Test]
    public function constructorRejectsEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host');

        new NodeEndpoint('', 9000);
    }

    #[Test]
    public function constructorRejectsNegativePort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port');

        new NodeEndpoint('localhost', -1);
    }

    #[Test]
    public function constructorRejectsPortAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port');

        new NodeEndpoint('localhost', 65536);
    }
}
