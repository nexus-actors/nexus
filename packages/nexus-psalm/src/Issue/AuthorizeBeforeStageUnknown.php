<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class AuthorizeBeforeStageUnknown extends PluginIssue
{
    /** @param list<string> $validStages */
    public function __construct(
        string $className,
        string $methodName,
        string $stage,
        array $validStages,
        CodeLocation $location,
    ) {
        parent::__construct(
            sprintf(
                "#[Authorize(before: '%s')] on %s::%s() names a stage that is not in PipelineStage. "
                . 'Valid stages: [%s].',
                $stage,
                $className,
                $methodName,
                implode(', ', $validStages),
            ),
            $location,
        );
    }
}
