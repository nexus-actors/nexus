<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\AbstractCompositeIdentifier;
use Monadial\Nexus\Ddd\Core\Identity\CompositeIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractCompositeIdentifier::class)]
final class CompositeIdentifierTest extends TestCase
{
    #[Test]
    public function canonicalValueJoinsComponentsWithColon(): void
    {
        $id = new TenantOrderId('acme', 'order-1');
        self::assertSame('acme:order-1', $id->value());
    }

    #[Test]
    public function fromStringRoundtripsValueComponents(): void
    {
        $original = new TenantOrderId('acme', 'order-1');
        $rehydrated = TenantOrderId::fromString($original->value());
        self::assertTrue($rehydrated->equals($original));
        self::assertSame(['tenant' => 'acme', 'order' => 'order-1'], $rehydrated->components());
    }

    #[Test]
    public function urlEncodingHandlesColonInComponentValues(): void
    {
        $id = new TenantOrderId('foo:bar', 'baz');
        self::assertSame('foo%3Abar:baz', $id->value());
    }

    #[Test]
    public function differentTypesAreNotEqualEvenWithSameValue(): void
    {
        $id = new TenantOrderId('a', 'b');
        $other = $this->createMock(CompositeIdentifier::class);
        self::assertFalse($id->equals($other));
    }
}

/** @psalm-suppress MissingConstructor */
final readonly class TenantOrderId extends AbstractCompositeIdentifier
{
    public function __construct(string $tenant, string $order)
    {
        parent::__construct(['tenant' => $tenant, 'order' => $order]);
    }
}
