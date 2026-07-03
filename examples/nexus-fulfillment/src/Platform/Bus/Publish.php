<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

final readonly class Publish
{
    public function __construct(public object $event) {}
}
