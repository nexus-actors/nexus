<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Support;

use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Record;
use Override;

final class CapturingHandler implements Handler
{
    /** @var list<Record> */
    public array $records = [];

    #[Override]
    public function handle(Record $record): void
    {
        $this->records[] = $record;
    }
}
