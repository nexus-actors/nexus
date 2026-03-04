<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use ArrayObject;

interface CoroutineContext
{
    public function current(): ArrayObject;
}
