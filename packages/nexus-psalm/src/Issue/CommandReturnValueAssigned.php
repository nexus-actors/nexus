<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class CommandReturnValueAssigned extends PluginIssue
{
    public function __construct(CodeLocation $location)
    {
        parent::__construct(
            'CommandBus::dispatchCommand() returns void; assigning the return value to a variable is '
            . 'dead code. Use tryDispatch() if you need an Either<Throwable, Accepted> outcome.',
            $location,
        );
    }
}
