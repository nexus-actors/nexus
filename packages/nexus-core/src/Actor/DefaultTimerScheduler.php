<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;

/** @psalm-api */
final class DefaultTimerScheduler implements TimerScheduler
{
    /** @var array<string, Cancellable> */
    private array $timers = [];

    public function __construct(private readonly ActorRef $selfRef, private readonly Runtime $runtime) {}

    #[Override]
    public function startSingleTimer(string $key, object $message, Duration $delay): void
    {
        $this->cancel($key);

        $selfRef = $this->selfRef;
        $cancellable = $this->runtime->scheduleOnce(
            $delay,
            static function () use ($selfRef, $message): void {
                $selfRef->tell($message);
            },
        );

        $this->timers[$key] = $cancellable;
    }

    #[Override]
    public function startTimerAtFixedRate(
        string $key,
        object $message,
        Duration $interval,
        ?Duration $initialDelay = null,
    ): void {
        $this->cancel($key);

        $selfRef = $this->selfRef;
        $cancellable = $this->runtime->scheduleRepeatedly(
            $initialDelay ?? $interval,
            $interval,
            static function () use ($selfRef, $message): void {
                $selfRef->tell($message);
            },
        );

        $this->timers[$key] = $cancellable;
    }

    #[Override]
    public function startTimerWithFixedDelay(
        string $key,
        object $message,
        Duration $delay,
        ?Duration $initialDelay = null,
    ): void {
        $this->cancel($key);

        // Currently both fixedRate and fixedDelay delegate to scheduleRepeatedly.
        // The API distinction is preserved for when runtimes support it.
        $selfRef = $this->selfRef;
        $cancellable = $this->runtime->scheduleRepeatedly(
            $initialDelay ?? $delay,
            $delay,
            static function () use ($selfRef, $message): void {
                $selfRef->tell($message);
            },
        );

        $this->timers[$key] = $cancellable;
    }

    #[Override]
    public function cancel(string $key): void
    {
        if (isset($this->timers[$key])) {
            $this->timers[$key]->cancel();
            unset($this->timers[$key]);
        }
    }

    #[Override]
    public function cancelAll(): void
    {
        foreach ($this->timers as $cancellable) {
            $cancellable->cancel();
        }

        $this->timers = [];
    }

    #[Override]
    public function isTimerActive(string $key): bool
    {
        return isset($this->timers[$key]) && !$this->timers[$key]->isCancelled();
    }
}
