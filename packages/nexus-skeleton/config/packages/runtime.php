<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

return static fn(): FiberRuntime => new FiberRuntime();
