<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Monadial\Nexus\Observability\Observability;
use Override;
use Throwable;

/** @psalm-api */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly Observability $observability,
    ) {
        parent::__construct($connection);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->observability, $sql);
    }

    #[Override]
    public function query(string $sql): Result
    {
        if (!$this->observability->isEnabled()) {
            return parent::query($sql);
        }

        $span = SqlSpan::start($this->observability, $sql);

        try {
            return parent::query($sql);
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }

    #[Override]
    public function exec(string $sql): int|string
    {
        if (!$this->observability->isEnabled()) {
            return parent::exec($sql);
        }

        $span = SqlSpan::start($this->observability, $sql);

        try {
            return parent::exec($sql);
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }
}
