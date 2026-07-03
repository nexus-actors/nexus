<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
