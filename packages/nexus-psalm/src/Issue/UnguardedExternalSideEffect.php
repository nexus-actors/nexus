<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class UnguardedExternalSideEffect extends PluginIssue
{
    public function __construct(
        string $handlerClass,
        string $handlerMethod,
        string $externalCall,
        string $commandClass,
        CodeLocation $location,
    ) {
        parent::__construct(
            sprintf(
                'Handler %s::%s() calls external side-effect API %s but command %s has no '
                . '#[IdempotencyKey] attribute. Consider adding one to make redelivery safe.',
                $handlerClass,
                $handlerMethod,
                $externalCall,
                $commandClass,
            ),
            $location,
        );
    }
}
