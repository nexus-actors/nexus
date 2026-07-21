<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Net;

use InvalidArgumentException;
use Monadial\Nexus\Core\Net\Host;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(Host::class)]
final class HostTest extends TestCase
{
    #[Test]
    public function ofAcceptsIpv4(): void
    {
        $host = Host::of('192.168.1.1');

        self::assertSame('192.168.1.1', $host->value);
    }

    #[Test]
    public function ofAcceptsIpv6Loopback(): void
    {
        $host = Host::of('::1');

        self::assertSame('::1', $host->value);
    }

    #[Test]
    public function ofAcceptsIpv6Full(): void
    {
        $host = Host::of('2001:db8::1');

        self::assertSame('2001:db8::1', $host->value);
    }

    #[Test]
    public function ofAcceptsHostname(): void
    {
        $host = Host::of('example.com');

        self::assertSame('example.com', $host->value);
    }

    #[Test]
    public function ofAcceptsSingleCharLabel(): void
    {
        $host = Host::of('a');

        self::assertSame('a', $host->value);
    }

    #[Test]
    public function ofAccepts63CharLabel(): void
    {
        $label = str_repeat('a', 63);
        $host = Host::of($label);

        self::assertSame($label, $host->value);
    }

    #[Test]
    public function ofRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Host::of('');
    }

    #[Test]
    public function ofRejectsWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Host::of(' ');
    }

    #[Test]
    public function ofRejects64CharLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Host::of(str_repeat('a', 64));
    }

    #[Test]
    public function ofRejectsNameOver253Chars(): void
    {
        // Four 63-char labels = 255 chars total (including dots); exceeds 253-char max.
        $this->expectException(InvalidArgumentException::class);

        Host::of(
            str_repeat('a', 63) . '.' .
            str_repeat('b', 63) . '.' .
            str_repeat('c', 63) . '.' .
            str_repeat('d', 63),
        );
    }

    #[Test]
    public function ofRejectsLeadingHyphenLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Host::of('-lead.com');
    }

    #[Test]
    public function ofRejectsUnderscore(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Host::of('under_score.com');
    }

    #[Test]
    public function isIpReturnsTrueForIpv4(): void
    {
        self::assertTrue(Host::of('192.168.1.1')->isIp());
    }

    #[Test]
    public function isIpReturnsTrueForIpv6(): void
    {
        self::assertTrue(Host::of('::1')->isIp());
    }

    #[Test]
    public function isIpReturnsFalseForHostname(): void
    {
        self::assertFalse(Host::of('example.com')->isIp());
    }

    #[Test]
    public function isIpv6ReturnsFalseForIpv4(): void
    {
        self::assertFalse(Host::of('192.168.1.1')->isIpv6());
    }

    #[Test]
    public function isIpv6ReturnsTrueForIpv6(): void
    {
        self::assertTrue(Host::of('::1')->isIpv6());
    }

    #[Test]
    public function isIpv6ReturnsFalseForHostname(): void
    {
        self::assertFalse(Host::of('example.com')->isIpv6());
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        self::assertTrue(Host::of('example.com')->equals(Host::of('example.com')));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        self::assertFalse(Host::of('example.com')->equals(Host::of('other.com')));
    }

    #[Test]
    public function toStringReturnsValue(): void
    {
        self::assertSame('example.com', (string) Host::of('example.com'));
    }

    #[Test]
    #[DataProvider('loopbackHosts')]
    public function isLoopbackTrueForLoopbackHosts(string $value): void
    {
        self::assertTrue(Host::of($value)->isLoopback());
    }

    #[Test]
    #[DataProvider('nonLoopbackHosts')]
    public function isLoopbackFalseForExposedHosts(string $value): void
    {
        self::assertFalse(Host::of($value)->isLoopback());
    }

    /** @return iterable<string, array{string}> */
    public static function loopbackHosts(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv4 loopback range' => ['127.5.6.7'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'localhost' => ['localhost'];
    }

    /** @return iterable<string, array{string}> */
    public static function nonLoopbackHosts(): iterable
    {
        yield 'all interfaces ipv4' => ['0.0.0.0'];
        yield 'all interfaces ipv6' => ['::'];
        yield 'private ipv4' => ['10.0.0.1'];
        yield 'public ipv4' => ['203.0.113.5'];
        yield 'hostname' => ['example.com'];
    }
}
