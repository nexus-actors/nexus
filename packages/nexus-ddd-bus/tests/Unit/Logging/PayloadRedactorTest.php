<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Logging;

use Monadial\Nexus\Ddd\Bus\Attribute\Sensitive;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadRedactor::class)]
final class PayloadRedactorTest extends TestCase
{
    #[Test]
    public function preservesPlainProperties(): void
    {
        $message = new readonly class (42, 'abc') {
            public function __construct(public int $amount, public string $name) {}
        };

        self::assertSame(['amount' => 42, 'name' => 'abc'], new PayloadRedactor()->redact($message));
    }

    #[Test]
    public function redactsSensitiveProperty(): void
    {
        $message = new readonly class ('secret-token') {
            public function __construct(#[Sensitive] public string $cardToken) {}
        };

        self::assertSame(['cardToken' => '[REDACTED]'], new PayloadRedactor()->redact($message));
    }

    #[Test]
    public function redactsOnlySensitiveInMixedShape(): void
    {
        $message = new readonly class ('tok-x', 'order-1') {
            public function __construct(#[Sensitive] public string $cardToken, public string $orderId) {}
        };

        self::assertSame(
            ['cardToken' => '[REDACTED]', 'orderId' => 'order-1'],
            new PayloadRedactor()->redact($message),
        );
    }

    #[Test]
    public function returnsEmptyArrayForObjectWithoutProperties(): void
    {
        $message = new readonly class {};

        self::assertSame([], new PayloadRedactor()->redact($message));
    }
}
