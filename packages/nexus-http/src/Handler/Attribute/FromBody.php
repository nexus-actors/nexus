<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Deserialize the request body into the parameter's typed class via the
 * configured MessageSerializer.
 *
 * Requires HttpApp::withMessageSerializer() to be wired; compile-time error
 * otherwise.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromBody
{
}
