<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;

/**
 * Phase-1 trie: a thin wrapper around a single Route value (the result of concat(...)).
 *
 * Method/path optimization (real prefix tree) is deferred until benchmarking shows it's needed.
 * This keeps directive semantics authoritative while still providing the trie API for downstream
 * code that wants a single dispatch entry point.
 */
final readonly class DispatchTrie
{
    public function __construct(public Route $root) {}

    public function dispatch(RequestCtx $ctx): ?ResponseInterface
    {
        return ($this->root->run)($ctx);
    }
}
