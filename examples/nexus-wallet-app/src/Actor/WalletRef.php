<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Reply to EnsureWallet. The directory hands the caller an opaque ref
 * to the per-owner wallet actor — owner is implied by the request.
 */
final readonly class WalletRef
{
    /** @param ActorRef<object> $ref */
    public function __construct(public ActorRef $ref) {}
}
