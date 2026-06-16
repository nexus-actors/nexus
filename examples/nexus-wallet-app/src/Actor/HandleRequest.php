<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

/**
 * Audit/observation message sent fire-and-forget from the HTTP handler
 * to the per-request actor. Carries identifying fields only — the
 * actor neither replies nor blocks.
 */
final readonly class HandleRequest
{
    public function __construct(
        public string $ownerId,
        public string $action,
        public int $amountCents,
    ) {}
}
