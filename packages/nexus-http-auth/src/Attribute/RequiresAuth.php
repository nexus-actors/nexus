<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * PHP attribute that enforces authentication on a route handler class.
 *
 * Place `#[RequiresAuth]` on any handler class to declare that a valid,
 * authenticated `Principal` must be present on the request. `AuthorizationMiddleware`
 * reads this attribute at dispatch time and returns a `401 Unauthorized` response
 * if no principal was stamped by {@see AuthenticationMiddleware}.
 *
 * For finer-grained authorisation use the sibling attributes: `RequiresRole`
 * (exact role match), `RequiresAnyRole` (one of a set), `RequiresScope` (exact
 * scope match), and `RequiresAnyScope` (one of a set of scopes). All siblings
 * imply authentication and will also produce a 401 when no principal is present,
 * or a 403 when the principal lacks the required role or scope.
 *
 * Example:
 * ```php
 * #[RequiresAuth]
 * final class OrderListHandler
 * {
 *     public function __invoke(ServerRequestInterface $request): ResponseInterface
 *     {
 *         $principal = $request->getAttribute('principal'); // always non-null here
 *         // ...
 *     }
 * }
 * ```
 *
 * @see AuthenticationMiddleware  Middleware that populates the Principal on the request
 * @see RequiresRole              Sibling attribute enforcing a specific role
 * @see RequiresScope             Sibling attribute enforcing a specific OAuth scope
 *
 * @psalm-api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAuth {}
