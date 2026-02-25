<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use Monadial\Nexus\Runtime\Async\FutureSlot;
use Override;
use RuntimeException;
use Throwable;

final class TestFutureSlot implements FutureSlot
{
    private ?object $result = null;
    private ?Throwable $failure = null;
    private bool $resolved = false;

    #[Override]
    public function resolve(object $value): void
    {
        if ($this->resolved) {
            return;
        }

        $this->result = $value;
        $this->resolved = true;
    }

    #[Override]
    public function fail(Throwable $e): void
    {
        if ($this->resolved) {
            return;
        }

        $this->failure = $e;
        $this->resolved = true;
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    #[Override]
    public function await(): object
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->result === null) {
            throw new RuntimeException('TestFutureSlot: await() called before resolve()');
        }

        return $this->result;
    }
}
