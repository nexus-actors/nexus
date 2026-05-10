<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Secondary discoverability shortcut for multi-method services. The
 * canonical shape is implementing the
 * Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler marker interface;
 * this attribute is the exception, not the rule.
 *
 *     final class OrdersService {
 *         #[Handler]
 *         public function place(PlaceOrder $cmd): void { … }
 *
 *         #[Handler]
 *         public function cancel(CancelOrder $cmd): void { … }
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Handler {}
