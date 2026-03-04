<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Shutdown;

use Closure;
use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class GracefulShutdownHandler
{
    /**
     * @param Closure(Duration): void $shutdownFn
     */
    public function __construct(
        private readonly Closure $shutdownFn,
        private readonly Duration $timeout,
        private readonly ShutdownTimeoutBehavior $onTimeout,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function shutdown(): void
    {
        $this->logger->info('Nexus: graceful shutdown initiated', [
            'timeout_seconds' => $this->timeout->toSeconds(),
        ]);

        ($this->shutdownFn)($this->timeout);

        $this->logger->info('Nexus: shutdown complete');
    }
}
