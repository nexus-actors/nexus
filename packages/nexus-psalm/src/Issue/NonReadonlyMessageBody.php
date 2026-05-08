<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class NonReadonlyMessageBody extends PluginIssue
{
    public function __construct(string $className, string $markerInterface, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Message body %s implements %s but is not declared `final readonly class`.',
                $className,
                $markerInterface,
            ),
            $location,
        );
    }
}
