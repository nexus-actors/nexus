<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Reads the handler's `#[Validate]` attribute via the cached
 * `HandlerAttributeIndex`. Handlers without the attribute pass through
 * with no validator invocation, so non-validated commands skip the slot.
 * Non-empty `Violations` are lifted to `ValidationFailedException` — a
 * terminal failure (no retry will make an invalid message valid).
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class ValidationMiddleware implements Middleware
{
    public function __construct(private readonly Validator $validator, private readonly HandlerAttributeIndex $index) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isNone()) {
            return $next($envelope);
        }

        $resolved = $entry->getUnsafe();

        if ($resolved->attribute(Validate::class)->isNone()) {
            return $next($envelope);
        }

        $context = ValidationContext::default()
            ->withHeaders($envelope->metadata->headers);

        $violations = $this->validator->validate($envelope->message, $context);

        if (!$violations->isEmpty()) {
            throw ValidationFailedException::with($violations);
        }

        return $next($envelope);
    }
}
