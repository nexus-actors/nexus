<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\InvoiceCreated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValinorMessageSerializer::class)]
final class CustomTransformerTest extends TestCase
{
    #[Test]
    public function roundtripWithCustomConstructorForMoneyValueObject(): void
    {
        $registry = new TypeRegistry();
        $registry->register(InvoiceCreated::class, 'invoice.created');

        // Register a custom constructor so Valinor can deserialize "USD:99.95" -> Money
        $mapperBuilder = (new MapperBuilder())
            ->registerConstructor(Money::fromString(...));

        $serializer = new ValinorMessageSerializer($registry, $mapperBuilder);

        $original = new InvoiceCreated('INV-001', new Money('USD', 99.95));

        // Serialize: json_encode produces {"invoiceId":"INV-001","total":"USD:99.95"}
        // because Money implements JsonSerializable
        $json = $serializer->serialize($original);

        // Verify the wire format uses the compact string representation
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('INV-001', $decoded['invoiceId']);
        self::assertSame('USD:99.95', $decoded['total']);

        // Deserialize: Valinor uses the registered constructor to map "USD:99.95" -> Money
        $restored = $serializer->deserialize($json, 'invoice.created');

        self::assertInstanceOf(InvoiceCreated::class, $restored);
        self::assertSame('INV-001', $restored->invoiceId);
        self::assertSame('USD', $restored->total->currency);
        self::assertSame(99.95, $restored->total->amount);
    }

    #[Test]
    public function roundtripWithZeroAmountMoney(): void
    {
        $registry = new TypeRegistry();
        $registry->register(InvoiceCreated::class, 'invoice.created');

        $mapperBuilder = (new MapperBuilder())
            ->registerConstructor(Money::fromString(...));

        $serializer = new ValinorMessageSerializer($registry, $mapperBuilder);

        $original = new InvoiceCreated('INV-ZERO', new Money('EUR', 0.0));

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'invoice.created');

        self::assertInstanceOf(InvoiceCreated::class, $restored);
        self::assertSame('INV-ZERO', $restored->invoiceId);
        self::assertSame('EUR', $restored->total->currency);
        self::assertSame(0.0, $restored->total->amount);
    }

    #[Test]
    public function roundtripWithLargeAmountMoney(): void
    {
        $registry = new TypeRegistry();
        $registry->register(InvoiceCreated::class, 'invoice.created');

        $mapperBuilder = (new MapperBuilder())
            ->registerConstructor(Money::fromString(...));

        $serializer = new ValinorMessageSerializer($registry, $mapperBuilder);

        $original = new InvoiceCreated('INV-BIG', new Money('GBP', 1234567.89));

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'invoice.created');

        self::assertInstanceOf(InvoiceCreated::class, $restored);
        self::assertSame('INV-BIG', $restored->invoiceId);
        self::assertSame('GBP', $restored->total->currency);
        self::assertSame(1234567.89, $restored->total->amount);
    }

    #[Test]
    public function customMapperBuilderDoesNotAffectNonCustomTypes(): void
    {
        $registry = new TypeRegistry();
        $registry->register(InvoiceCreated::class, 'invoice.created');

        // Even with a custom constructor registered, the MapperBuilder
        // should still handle normal types correctly
        $mapperBuilder = (new MapperBuilder())
            ->registerConstructor(Money::fromString(...));

        $serializer = new ValinorMessageSerializer($registry, $mapperBuilder);

        // InvoiceCreated itself doesn't need custom handling — only its Money field does
        $original = new InvoiceCreated('INV-NORMAL', new Money('JPY', 15000.0));

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'invoice.created');

        self::assertInstanceOf(InvoiceCreated::class, $restored);
        self::assertSame('INV-NORMAL', $restored->invoiceId);
        self::assertSame('JPY', $restored->total->currency);
        self::assertSame(15000.0, $restored->total->amount);
    }
}
