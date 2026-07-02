<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing\Attribute;

use Monadial\Nexus\Http\Routing\Attribute\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Route('GET', '/users/{id}', name: 'users.show', middleware: ['Auth'])]
#[Route('GET', '/users', name: 'users.index')]
final class _AttributeFixture {}

#[CoversClass(Route::class)]
final class RouteAttributeTest extends TestCase
{
    #[Test]
    public function attribute_is_repeatable_and_carries_arguments(): void
    {
        $attrs = (new ReflectionClass(_AttributeFixture::class))->getAttributes(Route::class);

        self::assertCount(2, $attrs);
        $first = $attrs[0]->newInstance();
        self::assertSame('GET', $first->method);
        self::assertSame('/users/{id}', $first->path);
        self::assertSame('users.show', $first->name);
        self::assertSame(['Auth'], $first->middleware);
    }
}
