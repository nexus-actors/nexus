<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * #[Authorize(policy: 'order.cancel', subject: 'App\\Subjects\\OrderSubject::resolve')]
 *   Canonical (callable) form: `Class::method` (public static); receives
 *   ($message, MessageContext) and returns the subject. Keeps the bus
 *   ignorant of message-internal field naming.
 *
 * #[Authorize(policy: 'order.cancel', subject: 'orderId')]
 *   Shortcut form: names a property on the message class. Convenient for
 *   simple cases but couples the bus configuration to property names —
 *   prefer the callable form whenever the subject derivation is non-trivial.
 *
 * The `before:` field flips pipeline ordering — set to 'validation' to
 * run Authorize before Validate. Validated by MiddlewareOrderingRule.
 *
 * The ?string types on subject and before are PHP-attribute-default
 * exceptions to the no-null rule (Option::none() is not a const expression).
 * The bus reads the attribute and immediately wraps with Option::fromNullable.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Authorize
{
    public function __construct(public string $policy, public ?string $subject = null, public ?string $before = null) {}
}
