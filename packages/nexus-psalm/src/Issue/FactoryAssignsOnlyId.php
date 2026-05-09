<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class FactoryAssignsOnlyId extends PluginIssue
{
    public function __construct(string $className, string $methodName, string $propertyName, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Aggregate factory %s::%s() assigns to property $%s. Aggregate factories must call '
                . 'new self($id) and recordThat(new SomeEvent(...)) only — state must flow through apply() '
                . 'after recordThat(), never via direct property assignment in the factory body.',
                $className,
                $methodName,
                $propertyName,
            ),
            $location,
        );
    }
}
