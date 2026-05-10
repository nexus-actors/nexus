<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 *     #[IdempotencyKey(field: 'clientRequestId')]
 *     readonly class PlaceOrder { …; public string $clientRequestId; … }
 *
 * Validated by IdempotencyKeyFieldExistsRule (Phase 17).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IdempotencyKey
{
    public function __construct(public string $field) {}
}
