<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEvent;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEventPublisher;
use Override;

/**
 * Test double recording every published {@see MembershipEvent} in order.
 */
final class RecordingEventPublisher implements MembershipEventPublisher
{
    /** @var list<MembershipEvent> */
    private array $events = [];

    #[Override]
    public function publish(MembershipEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<MembershipEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @template T of MembershipEvent
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        $matched = [];

        foreach ($this->events as $event) {
            if ($event instanceof $type) {
                $matched[] = $event;
            }
        }

        return $matched;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
