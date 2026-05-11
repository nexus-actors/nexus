<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class ValidatedCommandNotReadonly extends PluginIssue
{
    public function __construct(string $commandClass, string $handlerClass, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Command %s is referenced by a #[Validate] handler (%s) but is not declared '
                . '`final readonly class`. Validated commands MUST be readonly so validation '
                . 'observes the same immutable state the handler will receive.',
                $commandClass,
                $handlerClass,
            ),
            $location,
        );
    }
}
