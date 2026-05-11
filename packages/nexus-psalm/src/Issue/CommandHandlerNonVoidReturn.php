<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class CommandHandlerNonVoidReturn extends PluginIssue
{
    public function __construct(string $className, string $methodName, string $actualReturn, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Command handler %s::%s() declared return type %s; commands are pure CQS — '
                . "handler methods MUST declare ': void'.",
                $className,
                $methodName,
                $actualReturn,
            ),
            $location,
        );
    }
}
