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
use function str_starts_with;
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
    private const string SCHEME = 'tcp://';

    public function __construct(public Host $host, public Port $port) {}

    /**
     * Parse a 'host:port' string into a NodeEndpoint.
     *
     * Tolerates an optional leading 'tcp://' scheme (stripped before parsing).
     * Splits on the last colon so unbracketed IPv4 addresses work correctly.
     * IPv6 bracket form (`[::1]:7355`) is out of scope for v1.
     *
     * @throws InvalidArgumentException when the string is malformed or the port is out of range.
     */
    public static function fromString(string $hostPort): self
    {
        if (str_starts_with($hostPort, self::SCHEME)) {
            $hostPort = substr($hostPort, strlen(self::SCHEME));
        }

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

    /**
     * Parses a URI-style endpoint ("tcp://host:port"). Only the tcp scheme is
     * accepted; the canonical textual form (__toString, wire payloads, map
     * keys) remains bare "host:port" — the URI form is config/display surface
     * for the transport SPI (spec §3.4.1).
     *
     * @throws InvalidArgumentException
     */
    public static function fromUri(string $uri): self
    {
        if (!str_starts_with($uri, self::SCHEME)) {
            throw new InvalidArgumentException("Unsupported endpoint URI scheme: {$uri}");
        }

        return self::fromString($uri);
    }

    public function toUri(): string
    {
        return self::SCHEME . (string) $this;
    }

    #[Override]
    public function __toString(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
