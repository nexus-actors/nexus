<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use ArrayObject;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContext;
use Override;

final class MockCoroutineContext implements CoroutineContext
{
    private readonly ArrayObject $context;

    public function __construct()
    {
        $this->context = new ArrayObject();
    }

    #[Override]
    public function current(): ArrayObject
    {
        return $this->context;
    }
}
