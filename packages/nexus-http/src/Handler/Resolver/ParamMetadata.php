<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Compile-time description of a single resolved parameter. Produced by a
 * ParamResolver::compile() call; consumed at request time by the SAME
 * resolver via its back-ref ($metadata->resolver).
 *
 * Polymorphic dispatch — the framework never inspects $payload; only the
 * producing resolver does.
 *
 * `needsScope` is the framework-level signal: HandlerMetadata aggregates
 * this across all params on a handler to decide whether to allocate a
 * PerRequestActorScope per request.
 */
final readonly class ParamMetadata
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ParamResolver $resolver,
        public string $name,
        public ?string $type,
        public array $payload = [],
        public bool $needsScope = false,
    ) {}
}
