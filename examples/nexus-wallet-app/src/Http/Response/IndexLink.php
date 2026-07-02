<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class IndexLink
{
    public function __construct(public string $method, public string $href) {}
}
