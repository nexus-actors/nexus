<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Net;

use InvalidArgumentException;
use Override;
use Stringable;

use function filter_var;
use function preg_match;
use function sprintf;
use function strlen;

use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * @psalm-api
 *
 * Immutable host value object. Accepts IPv4 literals, IPv6 literals, and
 * RFC 1123 hostnames. IPv6 bracket form (`[::1]`) is documented out of scope for v1.
 *
 * RFC 1123 hostname rules: total length 1–253 chars; labels 1–63 chars;
 * alphanumeric and interior hyphens only; no leading or trailing hyphen per label;
 * underscores are disallowed.
 *
 * @example
 * $host = Host::of('10.0.0.1');
 * echo $host;           // '10.0.0.1'
 * $host->isIp();        // true
 * $host->isIpv6();      // false
 *
 * $host6 = Host::of('::1');
 * $host6->isIp();       // true
 * $host6->isIpv6();     // true
 *
 * $hostname = Host::of('example.com');
 * $hostname->isIp();    // false
 */
final readonly class Host implements Stringable
{
    /**
     * RFC 1123 hostname regex. Each label: 1 char (alphanumeric) or 2–63 chars
     * (starts and ends with alphanumeric, interior may contain hyphens).
     * Labels are separated by dots. Underscores and leading/trailing hyphens rejected.
     */
    private const string HOSTNAME_REGEX =
        '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/';

    private function __construct(public string $value) {}

    /**
     * Create a Host from a string value.
     *
     * Accepts IPv4 literals, IPv6 literals, and RFC 1123 hostnames.
     *
     * @throws InvalidArgumentException when the value is empty or not a valid host.
     */
    public static function of(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Host value must not be empty.');
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false && !self::isValidRfc1123Hostname($value)) {
            throw new InvalidArgumentException(
                sprintf("'%s' is not a valid IPv4/IPv6 address or RFC 1123 hostname.", $value),
            );
        }

        return new self($value);
    }

    /**
     * Returns true when the value is an IPv4 or IPv6 literal.
     */
    public function isIp(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Returns true only when the value is an IPv6 literal.
     */
    public function isIpv6(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function isValidRfc1123Hostname(string $value): bool
    {
        if (strlen($value) > 253) {
            return false;
        }

        return preg_match(self::HOSTNAME_REGEX, $value) === 1;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
