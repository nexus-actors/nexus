<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Doctrine\DBAL\Connection;

/** @psalm-api */
interface ConnectionFactory
{
    public function create(): Connection;
}
