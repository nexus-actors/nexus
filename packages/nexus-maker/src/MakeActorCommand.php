<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function lcfirst;
use function mkdir;
use function preg_replace;
use function sprintf;
use function strtolower;
use function ucfirst;

/**
 * @psalm-api registered via MakerCommands::all()
 */
#[AsCommand('make:actor', 'Generate an #[AsActor] handler in src/Actor')]
final class MakeActorCommand extends Command
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Actor;

        use Monadial\Nexus\App\AsActor;
        use Monadial\Nexus\Core\Actor\ActorContext;
        use Monadial\Nexus\Core\Actor\ActorHandler;
        use Monadial\Nexus\Core\Actor\Behavior;
        use Override;

        /**
         * @implements ActorHandler<object>
         */
        #[AsActor('%s')]
        final readonly class %sActor implements ActorHandler
        {
            #[Override]
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return match (true) {
                    // $message instanceof %sMessage => $this->on%s($ctx, $message),
                    default => Behavior::unhandled(),
                };
            }
        }

        PHP;

    private const string FUNCTIONAL_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Actor;

        use Monadial\Nexus\Core\Actor\ActorContext;
        use Monadial\Nexus\Core\Actor\Behavior;

        final class %sActor
        {
            public static function behavior(): Behavior
            {
                return Behavior::receive(static function (ActorContext $ctx, object $message): Behavior {
                    return match (true) {
                        // $message instanceof %sMessage => Behavior::same(),
                        default => Behavior::unhandled(),
                    };
                });
            }
        }

        PHP;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Actor name, e.g. Payment');
        $this->addOption('with-message', null, InputOption::VALUE_NONE, 'Also generate src/Message/<Name>Message.php');
        $this->addOption(
            'functional',
            null,
            InputOption::VALUE_NONE,
            'Generate a closure-based actor instead of an #[AsActor] handler',
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $raw */
        $raw = $input->getArgument('name');
        $name = ucfirst((string) preg_replace('/Actor$/', '', $raw));
        $file = $this->projectDir . '/src/Actor/' . $name . 'Actor.php';

        if (file_exists($file)) {
            $io->error(sprintf('%s already exists.', $file));

            return Command::FAILURE;
        }

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        $slug = strtolower(lcfirst($name));

        if ($input->getOption('functional') === true) {
            file_put_contents($file, sprintf(self::FUNCTIONAL_TEMPLATE, $name, $name));
            $io->success(sprintf('Created src/Actor/%sActor.php', $name));
            $io->writeln(sprintf(
                "Spawn it: \$system->spawn(Props::fromBehavior(%sActor::behavior()), '%s');",
                $name,
                $slug,
            ));
        } else {
            file_put_contents($file, sprintf(self::TEMPLATE, $slug, $name, $name, $name));
            $io->success(sprintf('Created src/Actor/%sActor.php', $name));
        }

        if ($input->getOption('with-message') === true) {
            $message = new MakeMessageCommand($this->projectDir);
            $message->setApplication($this->getApplication());

            return $message->run(
                new ArrayInput(['name' => $name . 'Message']),
                $output,
            );
        }

        return Command::SUCCESS;
    }
}
