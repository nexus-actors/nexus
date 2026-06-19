<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Constructor parameter attribute — injects the Principal stamped by
 * AuthenticationMiddleware onto the request. Mirrors #[FromActor] /
 * #[FromService] / #[FromBody] in nexus-http.
 *
 * Recognized by patched nexus-http/HandlerResolver and
 * nexus-http-ws/HandlerInstantiator via class-string lookup, so this
 * package doesn't need to be imported by those.
 *
 *   public function __construct(
 *       #[FromPrincipal] private readonly Principal $principal,
 *   ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromPrincipal {}
