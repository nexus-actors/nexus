<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class MutableClosureCapture extends PluginIssue
{
    public function __construct(string $variableName, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Actor factory closure captures variable $' . $variableName . ' by reference.'
            . ' By-reference captures in actor factories can lead to shared mutable state between actor instances.',
            $codeLocation,
        );
    }
}
