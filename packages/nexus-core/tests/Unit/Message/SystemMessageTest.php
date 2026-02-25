<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Message;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Message\DeadLetter;
use Monadial\Nexus\Core\Message\Kill;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Core\Message\Unwatch;
use Monadial\Nexus\Core\Message\Watch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(PoisonPill::class)]
#[CoversClass(Kill::class)]
#[CoversClass(Suspend::class)]
#[CoversClass(Resume::class)]
#[CoversClass(Watch::class)]
#[CoversClass(Unwatch::class)]
#[CoversClass(DeadLetter::class)]
final class SystemMessageTest extends TestCase
{
    #[Test]
    public function poisonPillImplementsSystemMessage(): void
    {
        $message = new PoisonPill();

        self::assertInstanceOf(SystemMessage::class, $message);
    }

    #[Test]
    public function killImplementsSystemMessage(): void
    {
        $message = new Kill();

        self::assertInstanceOf(SystemMessage::class, $message);
    }

    #[Test]
    public function suspendImplementsSystemMessage(): void
    {
        $message = new Suspend();

        self::assertInstanceOf(SystemMessage::class, $message);
    }

    #[Test]
    public function resumeImplementsSystemMessage(): void
    {
        $message = new Resume();

        self::assertInstanceOf(SystemMessage::class, $message);
    }

    #[Test]
    public function watchImplementsSystemMessageAndCarriesWatcher(): void
    {
        $watcher = $this->createActorRef('/user/watcher');
        $message = new Watch($watcher);

        self::assertInstanceOf(SystemMessage::class, $message);
        self::assertSame($watcher, $message->watcher);
    }

    #[Test]
    public function unwatchImplementsSystemMessageAndCarriesWatcher(): void
    {
        $watcher = $this->createActorRef('/user/watcher');
        $message = new Unwatch($watcher);

        self::assertInstanceOf(SystemMessage::class, $message);
        self::assertSame($watcher, $message->watcher);
    }

    #[Test]
    public function deadLetterImplementsSystemMessageAndCarriesProperties(): void
    {
        $msg = new stdClass();
        $sender = $this->createActorRef('/user/sender');
        $recipient = $this->createActorRef('/user/recipient');
        $deadLetter = new DeadLetter($msg, $sender, $recipient);

        self::assertInstanceOf(SystemMessage::class, $deadLetter);
        self::assertSame($msg, $deadLetter->message);
        self::assertSame($sender, $deadLetter->sender);
        self::assertSame($recipient, $deadLetter->recipient);
    }

    /**
     * @return ActorRef<object>
     */
    private function createActorRef(string $path): ActorRef
    {
        $actorPath = ActorPath::fromString($path);
        $ref = $this->createStub(ActorRef::class);
        $ref->method('path')->willReturn($actorPath);

        return $ref;
    }
}
