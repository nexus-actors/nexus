<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * #[Authorize(policy: 'order.cancel', subject: 'orderId')]
 *   String form: subject names a property on the command class.
 *
 * #[Authorize(policy: 'order.cancel', subject: 'App\\Subjects\\OrderSubject::resolve')]
 *   Callable form: 'Class::method' (public static); receives ($message, MessageContext): mixed.
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
