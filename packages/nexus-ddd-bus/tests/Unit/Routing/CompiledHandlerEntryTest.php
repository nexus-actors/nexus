<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledHandlerEntry;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledHandlerEntry::class)]
final class CompiledHandlerEntryTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $validate = new Validate(groups: ['order']);
        $entry = new CompiledHandlerEntry(
            handlerClass: CompiledHandlerEntryFixtureHandler::class,
            attributes: [Validate::class => $validate],
            authorizeBeforeValidate: true,
            idempotencyOptedOut: false,
        );

        self::assertSame(CompiledHandlerEntryFixtureHandler::class, $entry->handlerClass);
        self::assertSame([Validate::class => $validate], $entry->attributes);
        self::assertTrue($entry->authorizeBeforeValidate);
        self::assertFalse($entry->idempotencyOptedOut);
    }

    #[Test]
    public function liftsIntoResolvedAttributesEntryPreservingAllFields(): void
    {
        $validate = new Validate();
        $entry = new CompiledHandlerEntry(
            handlerClass: CompiledHandlerEntryFixtureHandler::class,
            attributes: [Validate::class => $validate],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: true,
        );

        $resolved = $entry->toResolvedAttributesEntry();

        self::assertInstanceOf(ResolvedAttributesEntry::class, $resolved);
        self::assertSame(CompiledHandlerEntryFixtureHandler::class, $resolved->handlerClass);
        self::assertSame([Validate::class => $validate], $resolved->attributes);
        self::assertFalse($resolved->authorizeBeforeValidate);
        self::assertTrue($resolved->idempotencyOptedOut);
    }
}

final class CompiledHandlerEntryFixtureHandler {}
