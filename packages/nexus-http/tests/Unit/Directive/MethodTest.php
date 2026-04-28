<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\delete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\method as httpMethod;
use function Monadial\Nexus\Http\patch;
use function Monadial\Nexus\Http\post;
use function Monadial\Nexus\Http\put;

final class MethodTest extends TestCase
{
    #[Test]
    public function get_passes_only_for_GET(): void
    {
        $route = get(static fn() => complete(['ok' => true]));

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/')));
        self::assertNull(($route->run)(CtxFactory::with('POST', '/')));
    }

    #[Test]
    public function post_passes_only_for_POST(): void
    {
        $route = post(static fn() => complete(null));

        self::assertNotNull(($route->run)(CtxFactory::with('POST', '/')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }

    #[Test]
    public function put_delete_patch_each_match_their_verb(): void
    {
        self::assertNotNull((put(static fn() => complete(null))->run)(CtxFactory::with('PUT', '/')));
        self::assertNotNull((delete(static fn() => complete(null))->run)(CtxFactory::with('DELETE', '/')));
        self::assertNotNull((patch(static fn() => complete(null))->run)(CtxFactory::with('PATCH', '/')));
    }

    #[Test]
    public function method_is_a_generic_verb_directive(): void
    {
        $route = httpMethod('PROPFIND', static fn() => complete(null));

        self::assertNotNull(($route->run)(CtxFactory::with('PROPFIND', '/')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/')));
    }
}
