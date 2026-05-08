<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class InvalidEventListenerSignature extends PluginIssue
{
    public function __construct(string $className, string $reason, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Event listener %s has invalid signature: %s. Expected '
                . '`public function __invoke(ConcreteEvent $event, ?MessageContext $ctx = null): void`.',
                $className,
                $reason,
            ),
            $location,
        );
    }
}
