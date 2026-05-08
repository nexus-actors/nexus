<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Core\Identity\AbstractCompositeIdentifier;
use Monadial\Nexus\Ddd\Core\Identity\CompositeIdentifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbstractCompositeIdentifier::class)]
final class CompositeIdentifierTest extends TestCase
{
    #[Test]
    public function canonicalValueJoinsComponentValuesWithColon(): void
    {
        $tenant = new TestUlidId((new Ulid())->toBase32());
        $order = new TestUlidId((new Ulid())->toBase32());
        $id = new TenantOrderId($tenant, $order);

        self::assertSame($tenant->value() . ':' . $order->value(), $id->value());
    }

    #[Test]
    public function fromStringRoundtripsComponents(): void
    {
        $tenant = new TestUlidId((new Ulid())->toBase32());
        $order = new TestUlidId((new Ulid())->toBase32());
        $original = new TenantOrderId($tenant, $order);

        $rehydrated = TenantOrderId::fromString($original->value());

        self::assertTrue($rehydrated->equals($original));
        self::assertSame(
            ['tenant', 'order'],
            array_keys($rehydrated->components()),
        );
    }

    #[Test]
    public function equalityIsDeepAndCompositeIdentifierAware(): void
    {
        $tenantUlid = (new Ulid())->toBase32();
        $orderUlid = (new Ulid())->toBase32();

        $a = new TenantOrderId(new TestUlidId($tenantUlid), new TestUlidId($orderUlid));
        $b = new TenantOrderId(new TestUlidId($tenantUlid), new TestUlidId($orderUlid));
        $c = new TenantOrderId(new TestUlidId($tenantUlid), new TestUlidId((new Ulid())->toBase32()));

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function differentTypesAreNotEqualEvenWithSameValue(): void
    {
        $tenant = new TestUlidId((new Ulid())->toBase32());
        $order = new TestUlidId((new Ulid())->toBase32());
        $id = new TenantOrderId($tenant, $order);
        $other = $this->createMock(CompositeIdentifier::class);

        self::assertFalse($id->equals($other));
    }
}

/** @psalm-suppress MissingConstructor */
final readonly class TenantOrderId extends AbstractCompositeIdentifier
{
    public function __construct(TestUlidId $tenant, TestUlidId $order)
    {
        parent::__construct(['tenant' => $tenant, 'order' => $order]);
    }

    #[Override]
    public function value(): string
    {
        $components = $this->components();

        return $components['tenant']->value() . ':' . $components['order']->value();
    }

    #[Override]
    public static function fromString(string $value): static
    {
        $parts = explode(':', $value);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                sprintf('TenantOrderId expects "tenant:order" canonical form; got "%s".', $value),
            );
        }

        [$tenant, $order] = $parts;

        return new self(
            TestUlidId::fromString($tenant),
            TestUlidId::fromString($order),
        );
    }
}
