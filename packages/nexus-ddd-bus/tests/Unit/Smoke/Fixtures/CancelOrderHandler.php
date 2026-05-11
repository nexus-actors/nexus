<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;

final class CancelOrderHandler implements CommandHandler
{
    /** @var list<CancelOrder> */
    public array $invocations = [];

    public function __invoke(CancelOrder $command): void
    {
        $this->invocations[] = $command;
    }
}
