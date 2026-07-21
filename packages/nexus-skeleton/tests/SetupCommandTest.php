<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\SetupCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    private string $dir;

    /** @var list<list<string>> */
    private array $required = [];

    #[Test]
    public function non_interactive_takes_defaults_and_requires_nothing(): void
    {
        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->required);
        self::assertStringContainsString('bin/console make:actor', $tester->getDisplay());
        self::assertStringContainsString(
            'FiberRuntime',
            (string) file_get_contents($this->dir . '/config/packages/runtime.php'),
        );

        /** @var array{extra: array{nexus: array{architecture: string}}} $composer */
        $composer = json_decode((string) file_get_contents($this->dir . '/composer.json'), true);
        self::assertSame('minimal', $composer['extra']['nexus']['architecture']);
    }

    #[Test]
    public function swoole_without_extension_falls_back_to_fiber(): void
    {
        $tester = $this->tester(swooleLoaded: false);
        // architecture=default, runtime=swoole, "install anyway?"=no -> fiber,
        // persistence=none, observability=none, cluster=no, messenger=no
        $tester->setInputs(['', 'swoole', 'no', 'none', 'none', 'no', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ext-swoole >= 6.2.1 is not loaded', $tester->getDisplay());
        self::assertStringContainsString('Falling back to the Fiber runtime.', $tester->getDisplay());
        self::assertSame([], $this->required);
        self::assertStringContainsString(
            'FiberRuntime',
            (string) file_get_contents($this->dir . '/config/packages/runtime.php'),
        );
    }

    #[Test]
    public function swoole_choice_overwrites_runtime_config_and_requires_package(): void
    {
        $tester = $this->tester();
        // architecture=default, runtime=swoole, http=no, persistence=none, observability=none, cluster=no, messenger=no
        $tester->setInputs(['', 'swoole', 'no', 'none', 'none', 'no', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['nexus-actors/runtime-swoole']], $this->required);
        self::assertStringContainsString(
            'SwooleRuntime',
            (string) file_get_contents($this->dir . '/config/packages/runtime.php'),
        );
    }

    #[Test]
    public function experimental_choice_prints_warning_and_writes_config(): void
    {
        $tester = $this->tester();
        // runtime=fiber, persistence=memory (stable, no warning), observability=none,
        // cluster=yes (experimental, warns), messenger=no
        $tester->setInputs(['', 'fiber', 'memory', 'none', 'yes', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('TCP cluster is experimental, not production-ready', $tester->getDisplay());
        self::assertStringNotContainsString('Persistence (in-memory store) is experimental', $tester->getDisplay());
        self::assertFileExists($this->dir . '/config/packages/persistence.php');
        self::assertFileExists($this->dir . '/config/packages/cluster.php');
        self::assertSame([['nexus-actors/persistence', 'nexus-actors/cluster-tcp']], $this->required);
    }

    #[Test]
    public function http_question_is_skipped_on_fiber(): void
    {
        $tester = $this->tester();
        // runtime=fiber → no http question: persistence=none, observability=none, cluster=no, messenger=no
        $tester->setInputs(['', 'fiber', 'none', 'none', 'no', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('HTTP server', $tester->getDisplay());
    }

    #[Test]
    public function existing_module_config_is_not_overwritten(): void
    {
        file_put_contents($this->dir . '/config/packages/cluster.php', "<?php return ['keep' => true];\n");

        $tester = $this->tester();
        $tester->setInputs(['', 'fiber', 'none', 'none', 'yes', 'no']);
        $tester->execute([]);

        self::assertStringContainsString(
            'keep',
            (string) file_get_contents($this->dir . '/config/packages/cluster.php'),
        );
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    #[Test]
    public function fiber_default_warns_before_replacing_existing_swoole_runtime_config(): void
    {
        file_put_contents(
            $this->dir . '/config/packages/runtime.php',
            "<?php\n\nuse Monadial\\Nexus\\Runtime\\Swoole\\SwooleRuntime;\n\nreturn static fn(): SwooleRuntime => new SwooleRuntime();\n",
        );

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Replacing existing config/packages/runtime.php', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexus-setup-' . uniqid();
        mkdir($this->dir . '/config/packages', 0o777, true);
        file_put_contents($this->dir . '/config/packages/runtime.php', "<?php return static fn() => null;\n");
        file_put_contents($this->dir . '/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $this->required = [];
    }

    private function tester(bool $swooleLoaded = true): CommandTester
    {
        $runner = function (array $packages): int {
            $this->required[] = $packages;

            return 0;
        };

        return new CommandTester(new SetupCommand(
            $runner,
            $this->dir,
            static fn(string $extension): bool => $extension === 'swoole'
                ? $swooleLoaded
                : extension_loaded($extension),
        ));
    }
}
