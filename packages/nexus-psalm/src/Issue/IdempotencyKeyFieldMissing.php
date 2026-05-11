<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class IdempotencyKeyFieldMissing extends PluginIssue
{
    public function __construct(string $commandClass, string $fieldName, string $reason, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                "#[IdempotencyKey(field: '%s')] on %s: %s. The named property must exist on "
                . 'the class and be typed `string`.',
                $fieldName,
                $commandClass,
                $reason,
            ),
            $location,
        );
    }
}
