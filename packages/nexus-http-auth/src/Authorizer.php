<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Custom policy hook for #[Authorize(MyPolicy::class)]. Returns true to
 * allow, false to deny (yielding 403). The request is provided so policies
 * can inspect path params, headers, or other request state.
 *
 * Implementations should be stateless — the framework may cache one
 * instance per handler.
 */
interface Authorizer
{
    public function authorize(Principal $principal, ServerRequestInterface $request): bool;
}
