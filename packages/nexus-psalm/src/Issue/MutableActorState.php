<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class MutableActorState extends PluginIssue
{
    public function __construct(string $className, string $propertyName, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Actor handler "' . $className . '" has public mutable property "$' . $propertyName . '".'
            . ' Actor handlers should not expose mutable state. Make the property readonly or reduce its visibility.',
            $codeLocation,
        );
    }
}
