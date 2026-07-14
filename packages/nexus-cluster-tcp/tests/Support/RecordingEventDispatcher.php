<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 test double that records every dispatched event in order.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @template T of object
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
}
