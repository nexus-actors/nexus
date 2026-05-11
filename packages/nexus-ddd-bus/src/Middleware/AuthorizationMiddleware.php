<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationContext;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Reads the handler's `#[Authorize]` attribute via the cached
 * `HandlerAttributeIndex`. Handlers without the attribute pass through.
 * On a subject spec, the `SubjectResolver` resolves the runtime subject
 * using the in-flight `MessageContext`; when the bus has not pushed a
 * context yet (defensive path for callers outside the canonical
 * pipeline), the middleware synthesizes one from the envelope's
 * metadata + stamps so subject resolution still has a context value to
 * receive. `AuthorizationDecider::decide` is total — denial is a
 * thrown `AccessDeniedException`.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class AuthorizationMiddleware implements Middleware
{
    public function __construct(
        private readonly AuthorizationDecider $decider,
        private readonly SubjectResolver $subjectResolver,
        private readonly HandlerAttributeIndex $index,
        private readonly MessageContextStack $contextStack,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $entry = $this->index->lookup($envelope->message::class);

        if ($entry->isNone()) {
            return $next($envelope);
        }

        $resolved = $entry->getUnsafe();
        $authorize = $resolved->attribute(Authorize::class);

        if ($authorize->isNone()) {
            return $next($envelope);
        }

        $attribute = $authorize->getUnsafe();
        /** @var mixed $subject */
        $subject = $attribute->subject !== null
            ? $this->subjectResolver->resolve(
                $envelope->message,
                $attribute->subject,
                $this->contextStack->current()->getOrCall(
                    static fn(): MessageContext => new MessageContext($envelope->metadata, $envelope->stamps),
                ),
            )
            : null;

        $this->decider->decide(
            $attribute->policy,
            $subject,
            new AuthorizationContext(Option::none(), $envelope->metadata->headers, $envelope),
        );

        return $next($envelope);
    }
}
