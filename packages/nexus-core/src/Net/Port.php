<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Net;

use InvalidArgumentException;
use Override;
use Stringable;

use function sprintf;

/**
 * @psalm-api
 *
 * Immutable TCP/UDP port number value object. Valid range is 0–65535.
 * Port 0 is allowed and documented as "OS-assigned / ephemeral" — used for
 * bind endpoints where the OS picks the port.
 *
 * @example
 * $port = Port::of(7355);
 * echo $port;        // '7355'
 * $port->value;      // 7355 (int)
 * Port::of(0);       // valid — OS-assigned ephemeral port
 */
final readonly class Port implements Stringable
{
    private function __construct(public int $value) {}

    /**
     * Create a Port from an integer in the valid range 0–65535.
     *
     * @throws InvalidArgumentException when the value is outside the valid range.
     */
    public static function of(int $value): self
    {
        if ($value < 0 || $value > 65535) {
            throw new InvalidArgumentException(
                sprintf('Port value %d is outside the valid range 0–65535.', $value),
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
