<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class InvalidCommandHandlerSignature extends PluginIssue
{
    public function __construct(string $className, string $reason, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Command handler %s has invalid signature: %s. Expected '
                . '`public function __invoke(ConcreteCommand $command, ?MessageContext $ctx = null): void`.',
                $className,
                $reason,
            ),
            $location,
        );
    }
}
