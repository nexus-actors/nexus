<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Class-level attribute on handler classes. AuthorizationMiddleware
 * returns 401 if the request has no Principal.
 *
 *   #[RequiresAuth]
 *   final class MyHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAuth {}
