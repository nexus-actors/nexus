<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class UntypedActorRefInjection extends PluginIssue
{
    public function __construct(string $subject, CodeLocation $codeLocation)
    {
        parent::__construct(
            $subject . ' must declare a concrete message type, e.g. ActorRef<MyCommand>.'
            . ' Bare ActorRef and ActorRef<object> defeat typed messaging at the injection boundary.',
            $codeLocation,
        );
    }
}
