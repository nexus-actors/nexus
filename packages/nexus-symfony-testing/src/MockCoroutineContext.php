<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use ArrayObject;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;

final class MockCoroutineContext implements CoroutineContextInterface
{
    private readonly ArrayObject $context;

    public function __construct()
    {
        $this->context = new ArrayObject();
    }

    #[\Override]
    public function current(): ArrayObject
    {
        return $this->context;
    }
}
