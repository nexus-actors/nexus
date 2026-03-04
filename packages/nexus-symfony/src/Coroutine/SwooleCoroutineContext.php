<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use ArrayObject;
use Override;
use Swoole\Coroutine;

final class SwooleCoroutineContext implements CoroutineContext
{
    #[Override]
    public function current(): ArrayObject
    {
        /** @var ArrayObject */
        return Coroutine::getContext();
    }
}
