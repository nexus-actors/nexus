<?php

declare(strict_types=1);

namespace Nexus\Maker;

use InvalidArgumentException;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function ucfirst;

/**
 * @psalm-api registered via MakerCommands::all()
 */
#[AsCommand('make:message', 'Generate a readonly message class in src/Message')]
final class MakeMessageCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Message name, e.g. OrderPlaced');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            ProjectArchitecture::resolve($this->projectDir);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        /** @var string $raw */
        $raw = $input->getArgument('name');
        $name = ucfirst($raw);
        $file = $this->projectDir . '/src/Message/' . $name . '.php';

        if (file_exists($file)) {
            $io->error(sprintf('%s already exists.', $file));

            return Command::FAILURE;
        }

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        file_put_contents($file, (new PsrPrinter())->printFile(self::build($name)));
        $io->success(sprintf('Created src/Message/%s.php', $name));

        return Command::SUCCESS;
    }

    private static function build(string $name): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace('App\\Message');
        $class = $namespace->addClass($name);
        $class->setFinal()->setReadOnly();
        $class->addMethod('__construct');

        return $file;
    }
}
