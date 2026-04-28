<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\DispatchTrie;
use Monadial\Nexus\Http\Routing\RouteCompiler;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\concat;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;
use function Monadial\Nexus\Http\post;

#[CoversClass(DispatchTrie::class)]
#[CoversClass(RouteCompiler::class)]
final class DispatchTrieTest extends TestCase
{
    #[Test]
    public function dispatches_to_matching_method_and_path(): void
    {
        $tree = concat(
            path('orders', static fn() => get(static fn() => complete(['list']))),
            path('orders', static fn() => post(static fn() => complete(['created']))),
        );
        $trie = RouteCompiler::compile($tree);

        $get = $trie->dispatch(CtxFactory::with('GET', '/orders'));
        self::assertInstanceOf(ResponseInterface::class, $get);
        self::assertSame('["list"]', (string) $get->getBody());

        $post = $trie->dispatch(CtxFactory::with('POST', '/orders'));
        self::assertInstanceOf(ResponseInterface::class, $post);
        self::assertSame('["created"]', (string) $post->getBody());
    }

    #[Test]
    public function returns_null_when_path_does_not_match(): void
    {
        $tree = path('orders', static fn() => get(static fn() => complete(['ok'])));
        $trie = RouteCompiler::compile($tree);
        self::assertNull($trie->dispatch(CtxFactory::with('GET', '/missing')));
    }
}
