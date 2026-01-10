<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class NonReadonlyMessage extends PluginIssue
{
    public function __construct(string $className, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Message class "' . $className . '" should be readonly.'
            . ' Actor messages must be immutable to ensure safe concurrent messaging.',
            $codeLocation,
        );
    }
}
