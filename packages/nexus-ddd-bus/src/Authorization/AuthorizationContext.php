<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Runtime context passed to `AuthorizationDecider` implementations. The
 * principal is `Option<Principal>` per the no-null rule; adopters supply a
 * concrete `Principal` at HTTP/CLI boundaries. Builder methods use PHP 8.5
 * `clone($this, [...])` so adding fields here does not force every builder
 * to be edited.
 */
final readonly class AuthorizationContext
{
    /**
     * @param Option<Principal> $principal
     * @param Envelope<object> $envelope
     */
    public function __construct(public Option $principal, public Headers $headers, public Envelope $envelope) {}

    #[NoDiscard('withPrincipal returns a new context — the original is unchanged')]
    public function withPrincipal(Principal $principal): self
    {
        return clone($this, ['principal' => Option::some($principal)]);
    }
}
