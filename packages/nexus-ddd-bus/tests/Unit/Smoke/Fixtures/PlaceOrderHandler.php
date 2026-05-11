<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;

final class PlaceOrderHandler implements CommandHandler
{
    /** @var list<PlaceOrder> */
    public array $invocations = [];

    public function __invoke(PlaceOrder $command): void
    {
        $this->invocations[] = $command;
    }
}
