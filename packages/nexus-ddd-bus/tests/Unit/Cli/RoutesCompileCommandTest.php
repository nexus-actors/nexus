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

use function file_put_contents;
use function is_file;
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
