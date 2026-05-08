<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Pluggable storage for the in-flight context stack. Adapters that run
 * on coroutine runtimes (Swoole, ReactPHP) MUST provide a coroutine-keyed
 * implementation so concurrent handler chains do not see each other's
 * state.
 */
interface ContextStorage
{
    public function push(MessageContext $ctx): void;

    public function pop(): void;

    /** @return Option<MessageContext> */
    public function current(): Option;
}
