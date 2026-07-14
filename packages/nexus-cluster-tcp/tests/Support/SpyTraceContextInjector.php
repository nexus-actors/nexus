<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Cluster\Tcp\Messaging\TraceContextInjector;
use Override;

final class SpyTraceContextInjector implements TraceContextInjector
{
    public int $injectCount = 0;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(private readonly array $headers = ['traceparent' => 'spy-trace']) {}

    /**
     * @return array<string, string>
     */
    #[Override]
    public function inject(): array
    {
        ++$this->injectCount;

        return $this->headers;
    }
}
