<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;

/**
 * @template T of object
 */
final readonly class Props
{
    /**
     * @param Behavior<T> $behavior
     * @param MailboxConfig $mailbox
     * @param Option<object> $supervision  Will be typed as SupervisionStrategy in Task 5b
     */
    private function __construct(
        public Behavior $behavior,
        public MailboxConfig $mailbox,
        public Option $supervision,
    ) {}

    /**
     * @template U of object
     * @param Behavior<U> $behavior
     * @return Props<U>
     */
    public static function fromBehavior(Behavior $behavior): self
    {
        /** @var Option<object> $none */
        $none = Option::none(); // @phpstan-ignore varTag.type

        return new self($behavior, MailboxConfig::unbounded(), $none);
    }

    /**
     * @return Props<T>
     */
    public function withMailbox(MailboxConfig $config): self
    {
        return new self($this->behavior, $config, $this->supervision);
    }

    /**
     * @return Props<T>
     */
    public function withSupervision(object $strategy): self
    {
        return new self($this->behavior, $this->mailbox, Option::some($strategy));
    }
}
