<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeEndpoint::class)]
final class NodeEndpointTest extends TestCase
{
    #[Test]
    public function fromStringParsesHostnameAndPort(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:9000');

        self::assertSame('localhost', (string) $endpoint->host);
        self::assertSame(9000, $endpoint->port->value);
    }

    #[Test]
    public function fromStringWithIpv4Address(): void
    {
        $endpoint = NodeEndpoint::fromString('192.168.1.100:7355');

        self::assertSame('192.168.1.100', (string) $endpoint->host);
        self::assertSame(7355, $endpoint->port->value);
    }

    #[Test]
    public function fromStringPortZeroIsValid(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:0');

        self::assertSame(0, $endpoint->port->value);
    }

    #[Test]
    public function fromStringMaxPortIsValid(): void
    {
        $endpoint = NodeEndpoint::fromString('localhost:65535');

        self::assertSame(65535, $endpoint->port->value);
    }

    #[Test]
    public function fromStringRejectsPortAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);

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
    public function fromStringRoundTripWithHostname(): void
    {
        self::assertSame('localhost:9000', (string) NodeEndpoint::fromString('localhost:9000'));
    }

    #[Test]
    public function fromStringRoundTripWithIpv4(): void
    {
        self::assertSame('192.168.1.1:7355', (string) NodeEndpoint::fromString('192.168.1.1:7355'));
    }

    #[Test]
    public function toStringFormat(): void
    {
        $endpoint = new NodeEndpoint(Host::of('localhost'), Port::of(9000));

        self::assertSame('localhost:9000', (string) $endpoint);
    }
}
