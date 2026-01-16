<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class BlockingCallInHandler extends PluginIssue
{
    public function __construct(string $functionName, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Blocking call "' . $functionName . '()" detected in actor handler.'
            . ' Blocking operations starve the actor runtime. Use async alternatives or schedule work externally.',
            $codeLocation,
        );
    }
}
