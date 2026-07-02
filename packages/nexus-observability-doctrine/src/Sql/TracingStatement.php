<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Monadial\Nexus\Observability\Observability;
use Override;
use Throwable;

/** @psalm-api */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly Observability $observability,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    #[Override]
    public function execute(): Result
    {
        if (!$this->observability->isEnabled()) {
            return parent::execute();
        }

        $span = SqlSpan::start($this->observability, $this->sql);

        try {
            return parent::execute();
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }
}
