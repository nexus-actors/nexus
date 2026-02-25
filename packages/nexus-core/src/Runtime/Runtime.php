<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Runtime;

use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Actor\FutureSlot;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;

/** @psalm-api */
interface Runtime
{
    public function name(): string;

    public function createMailbox(MailboxConfig $config): Mailbox;

    /**
     * Create a lightweight value slot for the ask pattern.
     * The caller is responsible for scheduling timeout failures.
     */
    public function createFutureSlot(): FutureSlot;

    public function spawn(callable $actorLoop): string;

    public function scheduleOnce(Duration $delay, callable $callback): Cancellable;

    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable;

    public function yield(): void;

    public function sleep(Duration $duration): void;

    public function run(): void;

    public function shutdown(Duration $timeout): void;

    public function isRunning(): bool;
}
