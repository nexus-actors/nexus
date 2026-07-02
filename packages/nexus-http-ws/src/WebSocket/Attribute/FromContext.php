<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromContext {}
