<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Middleware;

/**
 * @internal
 *
 * Cached reflection result for a handler class — what auth attributes it
 * carries. Built once per class by AuthorizationMiddleware and kept in
 * an in-memory map.
 */
final readonly class AuthMetadata
{
    /**
     * @param list<list<string>>   $requiresScope    each inner list = ALL required
     * @param list<list<string>>   $requiresAnyScope each inner list = ANY required
     * @param list<list<string>>   $requiresRole
     * @param list<list<string>>   $requiresAnyRole
     * @param list<class-string>   $authorize
     */
    public function __construct(
        public bool $requiresAuth,
        public array $requiresScope,
        public array $requiresAnyScope,
        public array $requiresRole,
        public array $requiresAnyRole,
        public array $authorize,
    ) {}

    public function hasAnyAttribute(): bool
    {
        return $this->requiresAuth
            || $this->requiresScope !== []
            || $this->requiresAnyScope !== []
            || $this->requiresRole !== []
            || $this->requiresAnyRole !== []
            || $this->authorize !== [];
    }
}
