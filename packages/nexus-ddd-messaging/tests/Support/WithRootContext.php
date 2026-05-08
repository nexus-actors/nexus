<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 *
 * Test helper: wrap a callback in a fresh root MessageContext so handler
 * unit tests don't have to spell out the context-installation each time.
 *
 * Composes a MessageContextStack via constructor — no global state. Does not
 * require a NodeId; vector clocks are opt-in via VectorClockStamp.
 */
final readonly class WithRootContext
{
    public function __construct(private MessageContextStack $stack, private ClockInterface $clock,) {}

    public static function default(): self
    {
        return new self(MessageContextStack::default(), new SystemClock());
    }

    public function stack(): MessageContextStack
    {
        return $this->stack;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        return $this->stack->within(
            new MessageContext(MessageMetadata::root($this->clock)),
            $callback,
        );
    }
}
