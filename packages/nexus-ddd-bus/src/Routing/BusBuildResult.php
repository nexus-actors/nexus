<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * The output of `BusBuilder::build()`. Carries the pre-computed
 * `HandlerAttributeIndex` (consumed by validation / authorization
 * middleware at runtime), the message-class → handler-class map
 * (consumed by `BusRegistry` in Phase 12b), and the ordered list of
 * adopter-supplied middleware splices (consumed by `Sync*Bus`
 * constructors in Phase 13).
 */
final readonly class BusBuildResult
{
    /**
     * @param array<class-string, class-string> $handlerMap
     * @param list<CustomMiddlewareRegistration> $customMiddlewares
     */
    public function __construct(
        public HandlerAttributeIndex $index,
        public array $handlerMap,
        public array $customMiddlewares,
    ) {}
}
