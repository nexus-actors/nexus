<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Exception\ReplayDispatchAttemptedException;
use Override;

/**
 * @psalm-api
 *
 * Sentinel installed during ES replay. Throws on push attempts —
 * any code that tries to dispatch during replay fails loudly instead
 * of silently corrupting the causation chain of an unrelated message.
 */
final class ReplayingContextStorage implements ContextStorage
{
    /** @return list<MessageContext> */
    #[Override]
    public function snapshot(): array
    {
        return [];
    }

    #[Override]
    public function push(MessageContext $ctx): void
    {
        throw ReplayDispatchAttemptedException::whileReplaying();
    }

    #[Override]
    public function pop(): void
    {
    }

    /** @return Option<MessageContext> */
    #[Override]
    public function current(): Option
    {
        return Option::none();
    }

    /** @param list<MessageContext> $stack */
    #[Override]
    public function restore(array $stack): void
    {
    }
}
