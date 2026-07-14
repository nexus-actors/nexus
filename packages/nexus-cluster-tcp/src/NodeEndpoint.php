<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use InvalidArgumentException;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Override;
use Stringable;

use function ctype_digit;
use function sprintf;
use function strrpos;
use function substr;

/**
 * @psalm-api
 *
 * Network endpoint (host + port) for a cluster node's TCP transport. The
 * "where to connect" complement to NodeAddress (the cluster identity).
 * IPv6 bracket form (`[::1]:7355`) is documented out of scope for v1.
 *
 * @example
 * $endpoint = NodeEndpoint::fromString('10.0.0.1:7355');
 * echo $endpoint->host; // '10.0.0.1'
 * echo $endpoint->port; // 7355
 * echo $endpoint; // '10.0.0.1:7355'
 */
final readonly class NodeEndpoint implements Stringable
{
    public function __construct(public Host $host, public Port $port) {}

    /**
     * Parse a 'host:port' string into a NodeEndpoint.
     *
     * Splits on the last colon so unbracketed IPv4 addresses work correctly.
     * IPv6 bracket form (`[::1]:7355`) is out of scope for v1.
     *
     * @throws InvalidArgumentException when the string is malformed or the port is out of range.
     */
    public static function fromString(string $hostPort): self
    {
        $colonPos = strrpos($hostPort, ':');

        if ($colonPos === false) {
            throw new InvalidArgumentException(
                sprintf("Invalid endpoint string '%s': expected 'host:port' format.", $hostPort),
            );
        }

        $hostPart = substr($hostPort, 0, $colonPos);
        $portPart = substr($hostPort, $colonPos + 1);

        if ($hostPart === '') {
            throw new InvalidArgumentException(
                sprintf("Invalid endpoint string '%s': host part must not be empty.", $hostPort),
            );
        }

        if ($portPart === '' || !ctype_digit($portPart)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Invalid endpoint string '%s': port part '%s' is not a valid integer.",
                    $hostPort,
                    $portPart,
                ),
            );
        }

        return new self(Host::of($hostPart), Port::of((int) $portPart));
    }

    #[Override]
    public function __toString(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
