<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class MissingTransactionalDeclaration extends PluginIssue
{
    public function __construct(string $className, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Class "' . $className . '" is annotated with #[Transactional] but none of its methods'
            . ' declare a "Doctrine\DBAL\Connection" or "Doctrine\ORM\EntityManagerInterface" parameter.'
            . ' Without one of those, the #[Transactional] middleware cannot open a transaction.',
            $codeLocation,
        );
    }
}
