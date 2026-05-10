<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Validation;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Runtime context passed to `Validator` implementations. The principal is
 * `Option<Principal>` per the no-null rule; adopters supply a concrete
 * `Principal` at HTTP/CLI boundaries. Builder methods use PHP 8.5
 * `clone($this, [...])` so adding fields here does not force every
 * builder to be edited.
 */
final readonly class ValidationContext
{
    /**
     * @param list<string> $groups
     * @param Option<Principal> $principal
     */
    public function __construct(public array $groups, public Option $principal, public Headers $headers) {}

    #[NoDiscard('ValidationContext::default returns the empty context — assign or use it')]
    public static function default(): self
    {
        return new self([], Option::none(), Headers::empty());
    }

    /** @param list<string> $groups */
    #[NoDiscard('withGroups returns a new context — the original is unchanged')]
    public function withGroups(array $groups): self
    {
        return clone($this, ['groups' => $groups]);
    }

    #[NoDiscard('withPrincipal returns a new context — the original is unchanged')]
    public function withPrincipal(Principal $principal): self
    {
        return clone($this, ['principal' => Option::some($principal)]);
    }

    #[NoDiscard('withHeaders returns a new context — the original is unchanged')]
    public function withHeaders(Headers $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }
}
