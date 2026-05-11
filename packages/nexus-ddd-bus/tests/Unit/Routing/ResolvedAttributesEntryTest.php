<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedAttributesEntry::class)]
final class ResolvedAttributesEntryTest extends TestCase
{
    #[Test]
    public function fieldsAreExposedAsConstructed(): void
    {
        $validate = new Validate();
        $entry = new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\PlaceOrderHandler',
            attributes: [Validate::class => $validate],
            authorizeBeforeValidate: true,
            idempotencyOptedOut: true,
        );

        self::assertSame('App\\Handler\\PlaceOrderHandler', $entry->handlerClass);
        self::assertSame([Validate::class => $validate], $entry->attributes);
        self::assertTrue($entry->authorizeBeforeValidate);
        self::assertTrue($entry->idempotencyOptedOut);
    }

    #[Test]
    public function attributeReturnsSomeWhenPresent(): void
    {
        $validate = new Validate();
        $entry = new ResolvedAttributesEntry(
            handlerClass: 'H',
            attributes: [Validate::class => $validate],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );

        $result = $entry->attribute(Validate::class);

        self::assertTrue($result->isSome());
        self::assertSame($validate, $result->getUnsafe());
    }

    #[Test]
    public function attributeReturnsNoneWhenAbsent(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: 'H',
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );

        self::assertTrue($entry->attribute(Authorize::class)->isNone());
    }
}
