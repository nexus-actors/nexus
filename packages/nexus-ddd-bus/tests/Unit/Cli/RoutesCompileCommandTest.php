<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Cli;

use Monadial\Nexus\Ddd\Bus\Cli\RoutesCompileCommand;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\RuntimeException as SymfonyRuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

use function chmod;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(RoutesCompileCommand::class)]
final class RoutesCompileCommandTest extends TestCase
{
    private string $snapshotPath;

    #[Test]
    public function successfulCompileWritesSnapshotAndReportsCount(): void
    {
        $builder = new BusBuilder()
            ->registerHandler(RoutesCompileCommandMessage::class, RoutesCompileCommandHandler::class);

        $command = new RoutesCompileCommand(
            $builder,
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute(['output' => $this->snapshotPath]);

        self::assertSame(SymfonyCommand::SUCCESS, $exit);
        self::assertFileExists($this->snapshotPath);
        self::assertStringContainsString('Compiled 1 handler(s)', $tester->getDisplay());

        /** @var mixed $snapshot */
        $snapshot = require $this->snapshotPath;
        self::assertInstanceOf(CompiledBusBootSnapshot::class, $snapshot);
    }

    #[Test]
    public function missingArgumentFails(): void
    {
        $command = new RoutesCompileCommand(
            new BusBuilder(),
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );
        $tester = new CommandTester($command);

        $this->expectException(SymfonyRuntimeException::class);
        $tester->execute([]);
    }

    #[Test]
    public function existingFileWithoutOverwriteFails(): void
    {
        file_put_contents($this->snapshotPath, '<?php return 0;');

        $command = new RoutesCompileCommand(
            new BusBuilder(),
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute(['output' => $this->snapshotPath]);

        self::assertSame(SymfonyCommand::FAILURE, $exit);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    #[Test]
    public function existingFileWithOverwriteSucceeds(): void
    {
        file_put_contents($this->snapshotPath, '<?php return 0;');

        $builder = new BusBuilder()
            ->registerHandler(RoutesCompileCommandMessage::class, RoutesCompileCommandHandler::class);
        $command = new RoutesCompileCommand(
            $builder,
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute(['output' => $this->snapshotPath, '--overwrite' => true]);

        self::assertSame(SymfonyCommand::SUCCESS, $exit);
        /** @var mixed $snapshot */
        $snapshot = require $this->snapshotPath;
        self::assertInstanceOf(CompiledBusBootSnapshot::class, $snapshot);
    }

    #[Test]
    public function nonExistentParentDirectoryFails(): void
    {
        $command = new RoutesCompileCommand(
            new BusBuilder(),
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute(['output' => '/tmp/this-path-definitely-does-not-exist-xyz/snapshot.php']);

        self::assertSame(SymfonyCommand::FAILURE, $exit);
        self::assertStringContainsString('Parent directory', $tester->getDisplay());
    }

    #[Test]
    public function readOnlyParentDirectoryFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped(
                'chmod-based writability is bypassed for the root user; run this case under a non-root UID.',
            );
        }

        $readOnlyDir = sys_get_temp_dir() . '/ddd-routes-readonly-' . __FUNCTION__;

        if (is_dir($readOnlyDir)) {
            chmod($readOnlyDir, 0o755);
            rmdir($readOnlyDir);
        }

        mkdir($readOnlyDir, 0o755);
        chmod($readOnlyDir, 0o555);

        try {
            $command = new RoutesCompileCommand(
                new BusBuilder(),
                new Composite([], 'misc'),
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
            );
            $tester = new CommandTester($command);

            $exit = $tester->execute(['output' => $readOnlyDir . '/snapshot.php']);

            self::assertSame(SymfonyCommand::FAILURE, $exit);
            self::assertStringContainsString('not writable', $tester->getDisplay());
        } finally {
            chmod($readOnlyDir, 0o755);
            rmdir($readOnlyDir);
        }
    }

    #[Test]
    public function carriesSymfonyAsCommandName(): void
    {
        $command = new RoutesCompileCommand(
            new BusBuilder(),
            new Composite([], 'misc'),
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
        );

        self::assertSame('ddd:routes:compile', $command->getName());
    }

    #[Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddd-routes-compile-cli-');
        self::assertNotFalse($path);
        $this->snapshotPath = $path;
        // The command refuses to overwrite without the flag; remove the placeholder so the success-path test starts clean.
        unlink($this->snapshotPath);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }
}

final readonly class RoutesCompileCommandMessage {}

final class RoutesCompileCommandHandler
{
    public function handle(RoutesCompileCommandMessage $message): void
    {
        // fixture: bare handler used to verify command writes a non-empty snapshot.
    }
}
