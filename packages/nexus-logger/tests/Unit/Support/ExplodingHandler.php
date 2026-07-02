<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Support;

use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Record;
use Override;
use RuntimeException;

final class ExplodingHandler implements Handler
{
    public int $calls = 0;

    #[Override]
    public function handle(Record $record): void
    {
        $this->calls++;

        throw new RuntimeException('boom');
    }
}
