<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bad: has #[Transactional] but no Connection or EntityManagerInterface parameter.
 */
#[Transactional]
final class BadTransactionalHandler
{
    public function __invoke(ServerRequestInterface $req): void
    {
        // no-op fixture
    }
}

/**
 * Good: has #[Transactional] and declares a Connection parameter.
 */
#[Transactional]
final class GoodTransactionalWithConnection
{
    public function __invoke(ServerRequestInterface $req, Connection $c): void
    {
        // no-op fixture
    }
}

/**
 * Good: has #[Transactional] and declares an EntityManagerInterface parameter.
 */
#[Transactional]
final class GoodTransactionalWithEntityManager
{
    public function __invoke(ServerRequestInterface $req, EntityManagerInterface $em): void
    {
        // no-op fixture
    }
}

/**
 * Good: no #[Transactional] attribute at all — no issue expected.
 */
final class PlainHandlerNoAttribute
{
    public function __invoke(ServerRequestInterface $req): void
    {
        // no-op fixture
    }
}
