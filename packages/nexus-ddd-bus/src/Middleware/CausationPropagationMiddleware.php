<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Tracks how deep a command-chain has nested and stamps the running
 * depth on the envelope's headers. Throws when the chain exceeds the
 * configured cap — runaway recursion is treated as a terminal failure.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class CausationPropagationMiddleware implements Middleware
{
    public function __construct(private readonly int $depthCap = 32) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $headers = $envelope->metadata->headers;

        $currentDepth = $headers->get(HeaderKeys::CAUSATION_DEPTH)
            ->map(static fn(int|float|string|bool $v): int => (int) $v)
            ->getOrElse(0);

        $newDepth = $currentDepth + 1;

        if ($newDepth > $this->depthCap) {
            throw CausationDepthExceededException::for($newDepth, $this->depthCap);
        }

        $newHeaders = $headers->with(HeaderKeys::CAUSATION_DEPTH, $newDepth);
        $newMetadata = $envelope->metadata->withHeaders($newHeaders);
        $newEnvelope = new Envelope($envelope->message, $newMetadata, $envelope->stamps);

        return $next($newEnvelope);
    }
}
