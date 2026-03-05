<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use BadMethodCallException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use NoDiscard;
use Override;
use PHPUnit\Framework\Assert;

/**
 * Test double for ActorRef that records all tell() calls.
 *
 * Use in unit tests to assert which messages were sent to an actor ref
 * without spinning up an actual actor system.
 *
 * @implements ActorRef<object>
 */
final class MockActorRef implements ActorRef
{
    private readonly ActorPath $path;

    /** @var list<object> */
    private array $toldMessages = [];

    public function __construct()
    {
        $this->path = ActorPath::fromString('/mock/actor');
    }

    #[Override]
    public function tell(object $message): void
    {
        $this->toldMessages[] = $message;
    }

    /**
     * @template R of object
     * @return Future<R>
     * @throws BadMethodCallException always — ask() is not supported on MockActorRef
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new BadMethodCallException('MockActorRef does not support ask().');
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }

    /** @return list<object> */
    public function toldMessages(): array
    {
        return $this->toldMessages;
    }

    /**
     * Assert that exactly one message of the given class was sent.
     *
     * @param class-string $messageClass
     */
    public function assertToldOnce(string $messageClass): void
    {
        $this->assertToldTimes($messageClass, 1);
    }

    /**
     * Assert that exactly $times messages of the given class were sent.
     *
     * @param class-string $messageClass
     */
    public function assertToldTimes(string $messageClass, int $times): void
    {
        $count = $this->countMessagesOf($messageClass);

        Assert::assertSame(
            $times,
            $count,
            sprintf(
                'Expected %d message(s) of type %s to be told, but got %d.',
                $times,
                $messageClass,
                $count,
            ),
        );
    }

    /**
     * Assert that no messages of the given class were sent.
     *
     * @param class-string $messageClass
     */
    public function assertNeverTold(string $messageClass): void
    {
        $this->assertToldTimes($messageClass, 0);
    }

    /**
     * Reset all recorded messages.
     */
    public function reset(): void
    {
        $this->toldMessages = [];
    }

    /** @param class-string $messageClass */
    private function countMessagesOf(string $messageClass): int
    {
        $count = 0;

        foreach ($this->toldMessages as $message) {
            if ($message instanceof $messageClass) {
                ++$count;
            }
        }

        return $count;
    }
}
