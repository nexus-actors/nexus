<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Reply;

/** @psalm-api */
final readonly class DepositResult
{
    public function __construct(
        public bool $accepted,
        public int $balanceCents,
        public ?string $rejectionReason = null,
    ) {}
}
