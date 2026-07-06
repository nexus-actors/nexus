<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use InvalidArgumentException;
use Override;
use Stringable;

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
    public function __construct(public string $host, public int $port,) {
        if ($host === '') {
            throw new InvalidArgumentException('NodeEndpoint host must not be empty.');
        }

        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException(
                sprintf('NodeEndpoint port %d is out of valid range 0–65535.', $port),
            );
        }
    }

    /**
     * Parse a 'host:port' string into a NodeEndpoint.
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

        $host = substr($hostPort, 0, $colonPos);
        $portString = substr($hostPort, $colonPos + 1);

        if ($host === '') {
            throw new InvalidArgumentException(
                sprintf("Invalid endpoint string '%s': host part must not be empty.", $hostPort),
            );
        }

        $normalizedPort = str_starts_with($portString, '-')
            ? substr($portString, 1)
            : $portString;

        if ($normalizedPort === '' || !ctype_digit($normalizedPort)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Invalid endpoint string '%s': port part '%s' is not a valid integer.",
                    $hostPort,
                    $portString,
                ),
            );
        }

        return new self($host, (int) $portString);
    }

    #[Override]
    public function __toString(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
