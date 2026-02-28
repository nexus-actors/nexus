<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Override;

/** @internal */
final class DefaultWorkerStartHandler implements WorkerStartHandler
{
    #[Override]
    public function onWorkerStart(WorkerNode $node): void
    {
        // no-op — pool runs without application-level startup logic
    }
}
