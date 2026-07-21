<?php

declare(strict_types=1);

namespace Nexus\Maker\Tests;

use Nexus\Maker\MakerCommands;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeCommandsTest extends TestCase
{
    private string $dir;

    #[Test]
    public function all_registers_both_commands(): void
    {
        $names = array_map(
            static fn($c): ?string => $c->getName(),
            MakerCommands::all($this->dir),
        );
        sort($names);

        self::assertSame(['make:actor', 'make:message'], $names);
    }

    #[Test]
    public function make_actor_generates_valid_handler(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Payment']);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/PaymentActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString("#[AsActor('payment')]", $code);
        self::assertStringContainsString('final readonly class PaymentActor implements ActorHandler', $code);

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_with_message_generates_both(): void
    {
        $this->runCommand('make:actor', ['name' => 'Payment', '--with-message' => true]);

        self::assertFileExists($this->dir . '/src/Actor/PaymentActor.php');
        self::assertFileExists($this->dir . '/src/Message/PaymentMessage.php');
    }

    #[Test]
    public function make_actor_functional_generates_closure_based_actor(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Payment', '--functional' => true]);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/PaymentActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString('Behavior::receive', $code);
        self::assertStringContainsString('public static function behavior(): Behavior', $code);
        self::assertStringNotContainsString('AsActor', $code);
        self::assertStringNotContainsString('ActorHandler', $code);

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_functional_displays_spawn_hint(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Payment', '--functional' => true]);

        self::assertStringContainsString(
            "Spawn it: \$system->spawn(Props::fromBehavior(PaymentActor::behavior()), 'payment');",
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function make_actor_functional_with_message_generates_both(): void
    {
        $this->runCommand('make:actor', ['name' => 'Payment', '--functional' => true, '--with-message' => true]);

        self::assertFileExists($this->dir . '/src/Actor/PaymentActor.php');
        self::assertFileExists($this->dir . '/src/Message/PaymentMessage.php');
    }

    #[Test]
    public function make_message_generates_readonly_class(): void
    {
        $tester = $this->runCommand('make:message', ['name' => 'OrderPlaced']);

        $tester->assertCommandIsSuccessful();
        $code = (string) file_get_contents($this->dir . '/src/Message/OrderPlaced.php');
        self::assertStringContainsString('final readonly class OrderPlaced', $code);

        exec('php -l ' . escapeshellarg($this->dir . '/src/Message/OrderPlaced.php'), $out, $exit);
        self::assertSame(0, $exit);
    }

    #[Test]
    public function generators_refuse_to_overwrite(): void
    {
        $this->runCommand('make:message', ['name' => 'OrderPlaced']);
        $tester = $this->runCommand('make:message', ['name' => 'OrderPlaced']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexus-maker-' . uniqid();
        mkdir($this->dir . '/src', 0o777, true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $name, array $input): CommandTester
    {
        foreach (MakerCommands::all($this->dir) as $command) {
            if ($command->getName() === $name) {
                $tester = new CommandTester($command);
                $tester->execute($input);

                return $tester;
            }
        }

        self::fail(sprintf('Command %s not registered', $name));
    }
}
