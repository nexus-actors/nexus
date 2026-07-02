<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Event;

/**
 * Emitted exactly once per wallet, on first interaction. Distinguishes
 * "never touched" from "balance is zero" in the event log — useful for
 * audit and for projecting onboarding metrics.
 *
 * @psalm-api
 */
final readonly class WalletOpened
{
    public function __construct(public string $ownerId) {}
}
