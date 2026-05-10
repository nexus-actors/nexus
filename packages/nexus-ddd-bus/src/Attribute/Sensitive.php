<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Property-level marker. The LoggingMiddleware redacts attributed
 * properties from log payloads at DEBUG.
 *
 *     readonly class PlaceOrder {
 *         public function __construct(
 *             public string $orderId,
 *             #[Sensitive] public string $cardToken,
 *         ) {}
 *     }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Sensitive {}
