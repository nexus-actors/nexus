<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Class-level idempotency opt-in / opt-out marker on the message class.
 * The optional `store` hint names a specific `IdempotencyStore` slot
 * when an adopter wires several (e.g., separate stores per bounded
 * context); `off: true` disables the two-phase reserve+commit for the
 * annotated message class.
 *
 * Without the attribute the bus uses the default `IdempotencyStore`
 * registered at boot. Under `Profile::Sync` both the reserve and commit
 * middleware self-disable regardless of this attribute, so the marker
 * primarily controls async/actor profile redelivery semantics.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Idempotent
{
    public function __construct(public ?string $store = null, public bool $off = false) {}
}
