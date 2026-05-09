<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class AggregateEmitsOnlyEvents extends PluginIssue
{
    public function __construct(string $aggregateClass, string $forbiddenCall, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Aggregate %s calls %s() — aggregates may emit domain events only (via recordThat()). '
                . 'Cross-aggregate flow goes through process managers reacting to events, not direct '
                . 'CommandBus / EventBus / QueryBus calls from inside the aggregate.',
                $aggregateClass,
                $forbiddenCall,
            ),
            $location,
        );
    }
}
