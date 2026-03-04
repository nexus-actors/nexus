<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use ArrayObject;

interface CoroutineContextInterface
{
    public function current(): ArrayObject;
}
