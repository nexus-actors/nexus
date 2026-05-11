<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function iterator_to_array;

#[CoversClass(HandlerAttributeIndex::class)]
final class HandlerAttributeIndexTest extends TestCase
{
    #[Test]
    public function lookupReturnsSomeForRegisteredMessageClass(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\PlaceOrderHandler',
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);

        $result = $index->lookup(stdClass::class);

        self::assertTrue($result->isSome());
        self::assertSame($entry, $result->getUnsafe());
    }

    #[Test]
    public function lookupReturnsNoneForUnregisteredMessageClass(): void
    {
        $index = new HandlerAttributeIndex([]);

        $result = $index->lookup(stdClass::class);

        self::assertTrue($result->isNone());
    }

    #[Test]
    public function allReturnsIterableOfEntries(): void
    {
        $entryA = new ResolvedAttributesEntry(
            handlerClass: 'A',
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );
        $entryB = new ResolvedAttributesEntry(
            handlerClass: 'B',
            attributes: [],
            authorizeBeforeValidate: true,
            idempotencyOptedOut: true,
        );
        $index = new HandlerAttributeIndex(['Msg\\A' => $entryA, 'Msg\\B' => $entryB]);

        $all = iterator_to_array($index->all());

        self::assertSame(['Msg\\A' => $entryA, 'Msg\\B' => $entryB], $all);
    }
}
