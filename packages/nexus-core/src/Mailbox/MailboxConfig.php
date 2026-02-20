<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable mailbox configuration.
 */
final readonly class MailboxConfig
{
    private function __construct(public int $capacity, public OverflowStrategy $strategy, public bool $bounded)
    {
    }

    public static function bounded(int $capacity, OverflowStrategy $strategy = OverflowStrategy::ThrowException): self
    {
        return new self($capacity, $strategy, true);
    }

    public static function unbounded(): self
    {
        return new self(PHP_INT_MAX, OverflowStrategy::ThrowException, false);
    }

    public function withCapacity(int $capacity): self
    {
        return clone($this, ['capacity' => $capacity]);
    }

    public function withStrategy(OverflowStrategy $strategy): self
    {
        return clone($this, ['strategy' => $strategy]);
    }
}
