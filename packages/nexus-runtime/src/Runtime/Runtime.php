<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Runtime;

use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

/** @psalm-api */
interface Runtime
{
    public function name(): string;

    /**
     * @template T of object
     * @return Mailbox<T>
     */
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
