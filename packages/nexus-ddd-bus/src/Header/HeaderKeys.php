<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Header;

/**
 * @psalm-api
 *
 * String constants for bus header names. All keys share the `nexus.`
 * prefix to namespace against application headers. Headers ride on
 * MessageMetadata::$headers (canonical Headers value object from
 * messaging upstream).
 */
final class HeaderKeys
{
    public const string CAUSATION_DEPTH = 'nexus.causation.depth';
    public const string IDEMPOTENCY_KEY = 'nexus.idempotency-key';
    public const string PRINCIPAL = 'nexus.principal';
    public const string REPLAY = 'nexus.replay';
    public const string RETRY_ATTEMPT = 'nexus.retry.attempt';
    public const string RETRY_BUDGET_REMAINING_MS = 'nexus.retry.budget_remaining_ms';
}
