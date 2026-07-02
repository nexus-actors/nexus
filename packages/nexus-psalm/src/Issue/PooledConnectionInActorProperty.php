<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class PooledConnectionInActorProperty extends PluginIssue
{
    public function __construct(string $className, string $propertyName, string $type, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Actor handler "' . $className . '" holds a pooled connection resource in property "$' . $propertyName . '"'
            . ' (type: ' . $type . ').'
            . ' Storing a pooled connection for the actor\'s entire lifetime defeats the connection pool.'
            . ' Borrow a connection per-message via ConnectionScope middleware instead.',
            $codeLocation,
        );
    }
}
