<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class InvalidQueryHandlerSignature extends PluginIssue
{
    public function __construct(string $className, string $reason, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'Query handler %s has invalid signature: %s. Expected '
                . '`public function __invoke(ConcreteQuery $query, ?MessageContext $ctx = null): TResult`.',
                $className,
                $reason,
            ),
            $location,
        );
    }
}
