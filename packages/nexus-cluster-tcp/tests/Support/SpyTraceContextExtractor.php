<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Cluster\Tcp\Messaging\TraceContextExtractor;
use Monadial\Nexus\Observability\Context\Context;
use Override;

final class SpyTraceContextExtractor implements TraceContextExtractor
{
    public int $extractCount = 0;

    /** @var list<array<string, string>> */
    public array $extracted = [];

    /**
     * @param array<string, string> $trace
     */
    #[Override]
    public function extract(array $trace): Context
    {
        ++$this->extractCount;
        $this->extracted[] = $trace;

        return Context::root();
    }
}
