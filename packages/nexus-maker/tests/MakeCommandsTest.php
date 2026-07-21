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
    public function make_actor_uses_camel_case_slug_for_multiword_names(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'OrderProcessor']);

        $tester->assertCommandIsSuccessful();
        $code = (string) file_get_contents($this->dir . '/src/Actor/OrderProcessorActor.php');
        self::assertStringContainsString("#[AsActor('orderProcessor')]", $code);
    }

    #[Test]
    public function make_actor_functional_displays_camel_case_spawn_hint_for_multiword_names(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'OrderProcessor', '--functional' => true]);

        self::assertStringContainsString(
            "Spawn it: \$system->spawn(Props::fromBehavior(OrderProcessorActor::behavior()), 'orderProcessor');",
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function make_actor_stateful_generates_stateful_handler(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Counter', '--type' => 'stateful']);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/CounterActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString("#[AsActor('counter')]", $code);
        self::assertStringContainsString('final readonly class CounterActor implements StatefulActorHandler', $code);
        self::assertStringContainsString('public function initialState(): int', $code);
        self::assertStringContainsString(
            'public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState',
            $code,
        );

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_event_sourced_generates_behavior_factory(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Order', '--type' => 'event-sourced']);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/OrderActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString('final class OrderActor', $code);
        self::assertStringNotContainsString('#[AsActor(', $code);
        self::assertStringContainsString('public static function behavior(string $id): Behavior', $code);
        self::assertStringContainsString('EventSourcedBehavior::create(', $code);
        self::assertStringContainsString(
            'static function (mixed $state, ActorContext $ctx, object $command): Effect {',
            $code,
        );
        self::assertStringContainsString('static function (mixed $state, object $event): mixed {', $code);

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_event_sourced_displays_persistence_requirement_note(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Order', '--type' => 'event-sourced']);

        self::assertStringContainsString('Requires: composer require nexus-actors/persistence', $tester->getDisplay());
    }

    #[Test]
    public function make_actor_durable_state_generates_behavior_factory(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Profile', '--type' => 'durable-state']);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/ProfileActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString('final class ProfileActor', $code);
        self::assertStringNotContainsString('#[AsActor(', $code);
        self::assertStringContainsString('public static function behavior(string $id): Behavior', $code);
        self::assertStringContainsString('DurableStateBehavior::create(', $code);
        self::assertStringContainsString(
            'static function (mixed $state, ActorContext $ctx, object $command): DurableEffect {',
            $code,
        );

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_rejects_unknown_type(): void
    {
        $tester = $this->runCommand('make:actor', ['name' => 'Bogus', '--type' => 'bogus']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unknown actor type "bogus"', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir . '/src/Actor/BogusActor.php');
    }

    #[Test]
    public function make_actor_rejects_conflicting_functional_and_type(): void
    {
        $tester = $this->runCommand(
            'make:actor',
            ['name' => 'Conflict', '--functional' => true, '--type' => 'stateful'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cannot combine --functional with --type=stateful', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir . '/src/Actor/ConflictActor.php');
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
