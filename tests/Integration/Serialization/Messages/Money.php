<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use JsonSerializable;

/**
 * Custom value object that requires a custom Valinor constructor for deserialization.
 *
 * Represented as a string like "USD:99.95" on the wire via JsonSerializable.
 * Valinor needs a registered constructor to deserialize this string back into Money.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(public string $currency, public float $amount)
    {
    }

    public static function fromString(string $value): self
    {
        $parts = explode(':', $value, 2);

        return new self($parts[0], (float) $parts[1]);
    }

    public function jsonSerialize(): string
    {
        return $this->currency . ':' . $this->amount;
    }
}
