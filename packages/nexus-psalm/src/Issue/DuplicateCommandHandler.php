<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class DuplicateCommandHandler extends PluginIssue
{
    public function __construct(
        string $commandClass,
        string $duplicateHandler,
        string $existingHandler,
        CodeLocation $location,
    ) {
        parent::__construct(
            sprintf(
                'Command %s has more than one handler: %s and %s. Each command must have exactly one handler.',
                $commandClass,
                $existingHandler,
                $duplicateHandler,
            ),
            $location,
        );
    }
}
