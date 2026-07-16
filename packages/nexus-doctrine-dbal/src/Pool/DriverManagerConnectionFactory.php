<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Override;

/**
 * @psalm-api
 *
 * @psalm-import-type Params from DriverManager
 */
final readonly class DriverManagerConnectionFactory implements ConnectionFactory
{
    /**
     * @param Params $params
     */
    public function __construct(private array $params, private ?Configuration $config = null) {}

    #[Override]
    public function create(): Connection
    {
        return DriverManager::getConnection($this->params, $this->config);
    }
}
