<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;
use Override;

/**
 * @psalm-api
 *
 * Default storage — per-process static stack. Correct for synchronous
 * PHP, Fiber-based runtimes, and any setting where one logical request
 * never yields control to another logical request mid-handler.
 */
final class StaticStackContextStorage implements ContextStorage
{
    /** @var list<MessageContext> */
    private array $stack = [];

    /** @return list<MessageContext> */
    #[Override]
    public function snapshot(): array
    {
        return $this->stack;
    }

    #[Override]
    public function push(MessageContext $ctx): void
    {
        $this->stack[] = $ctx;
    }

    #[Override]
    public function pop(): void
    {
        array_pop($this->stack);
    }

    /** @return Option<MessageContext> */
    #[Override]
    public function current(): Option
    {
        /** @var MessageContext|null $top — Psalm's CallMap signature for array_last is mixed regardless of input element type */
        $top = array_last($this->stack);

        if ($top === null) {
            return Option::none();
        }

        return Option::some($top);
    }

    /** @param list<MessageContext> $stack */
    #[Override]
    public function restore(array $stack): void
    {
        $this->stack = $stack;
    }
}
