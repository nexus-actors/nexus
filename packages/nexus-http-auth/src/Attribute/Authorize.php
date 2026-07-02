<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;
use Monadial\Nexus\Http\Auth\Authorizer;

/**
 * @psalm-api
 *
 * Custom policy delegation. AuthorizationMiddleware resolves the named
 * class (via PSR-11 container or no-args construction) and calls
 * Authorizer::authorize(Principal, ServerRequest). 403 if it returns false.
 *
 * The referenced class MUST implement Authorizer. Validated at compile
 * time — InvalidAuthorizerException at boot if not.
 *
 *   #[Authorize(OrderOwnerPolicy::class)]
 *   final class ShowOrderHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Authorize
{
    /** @param class-string<Authorizer> $authorizer */
    public function __construct(public string $authorizer) {}
}
