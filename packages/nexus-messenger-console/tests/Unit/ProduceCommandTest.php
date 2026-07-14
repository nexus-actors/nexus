<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Tests\Unit;

use Monadial\Nexus\Messenger\Console\ProduceCommand;
use Monadial\Nexus\Messenger\Console\Tests\Unit\Fixture\PingMessage;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ProduceCommand::class)]
final class ProduceCommandTest extends TestCase
{
    private RecordingSender $sender;
    private TypeRegistry $registry;
    private ProduceCommand $command;

    #[Test]
    public function publishesSingleMessage(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            'body' => '{"id":"hello"}',
            'type' => 'console.ping',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $this->sender->sent);
        self::assertInstanceOf(PingMessage::class, $this->sender->sent[0]->getMessage());
        self::assertSame('hello', $this->sender->sent[0]->getMessage()->id);
    }

    #[Test]
    public function publishesMultipleMessagesWithCountOption(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            '--count' => '5',
            'body' => '{"id":"x"}',
            'type' => 'console.ping',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(5, $this->sender->sent);
    }

    #[Test]
    public function failsOnZeroCount(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            '--count' => '0',
            'body' => '{"id":"x"}',
            'type' => 'console.ping',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--count', $tester->getDisplay());
        self::assertCount(0, $this->sender->sent);
    }

    #[Test]
    public function failsOnNonNumericCount(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            '--count' => 'abc',
            'body' => '{"id":"x"}',
            'type' => 'console.ping',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--count', $tester->getDisplay());
        self::assertCount(0, $this->sender->sent);
    }

    #[Test]
    public function failsOnUnknownType(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            'body' => '{"id":"x"}',
            'type' => 'unknown.type',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString("unknown.type", $tester->getDisplay());
        self::assertCount(0, $this->sender->sent);
    }

    #[Test]
    public function failsWithInvalidOnUndeserializableBody(): void
    {
        $tester = new CommandTester($this->command);

        $exitCode = $tester->execute([
            'body' => 'not-json-at-all',
            'type' => 'console.ping',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('deserialize', $tester->getDisplay());
        self::assertCount(0, $this->sender->sent);
    }

    protected function setUp(): void
    {
        $this->sender = new RecordingSender();
        $this->registry = new TypeRegistry();
        $this->registry->registerFromAttribute(PingMessage::class);
        $serializer = new ValinorMessageSerializer($this->registry);
        $this->command = new ProduceCommand($this->sender, $serializer, $this->registry);
    }
}
