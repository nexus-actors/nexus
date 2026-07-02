<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/**
 * Returned by `POST /wallet/deposit` and `POST /wallet/withdraw`. `accepted`
 * is `true` on success; `false` carries the `reason` and goes out with
 * status 422 from the handler.
 *
 * @psalm-api
 */
final readonly class WalletOperationResponse
{
    public function __construct(
        public string $ownerId,
        public bool $accepted,
        public int $balance,
        public ?string $reason = null,
    ) {}
}
