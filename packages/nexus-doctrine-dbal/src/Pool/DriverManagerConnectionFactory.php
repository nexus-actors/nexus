<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Override;

/** @psalm-api */
final class DriverManagerConnectionFactory implements ConnectionFactory
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(private readonly array $params, private readonly ?Configuration $config = null) {}

    #[Override]
    public function create(): Connection
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return DriverManager::getConnection($this->params, $this->config);
    }
}
