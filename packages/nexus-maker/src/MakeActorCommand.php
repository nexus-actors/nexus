<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
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
use function implode;
use function in_array;
use function is_dir;
use function lcfirst;
use function mkdir;
use function preg_replace;
use function sprintf;
use function ucfirst;

/**
 * @psalm-api registered via MakerCommands::all()
 */
#[AsCommand(
    'make:actor',
    'Generate an actor in src/Actor: --type=handler (default), functional, stateful, event-sourced, '
    . 'durable-state (--functional is a shorthand for --type=functional)',
)]
final class MakeActorCommand extends Command
{
    private const array TYPES = ['handler', 'functional', 'stateful', 'event-sourced', 'durable-state'];

    private const string NS_ACTOR = 'App\\Actor';
    private const string AS_ACTOR = 'Monadial\\Nexus\\App\\AsActor';
    private const string ACTOR_CONTEXT = 'Monadial\\Nexus\\Core\\Actor\\ActorContext';
    private const string ACTOR_HANDLER = 'Monadial\\Nexus\\Core\\Actor\\ActorHandler';
    private const string BEHAVIOR = 'Monadial\\Nexus\\Core\\Actor\\Behavior';
    private const string BEHAVIOR_WITH_STATE = 'Monadial\\Nexus\\Core\\Actor\\BehaviorWithState';
    private const string STATEFUL_ACTOR_HANDLER = 'Monadial\\Nexus\\Core\\Actor\\StatefulActorHandler';
    private const string EFFECT = 'Monadial\\Nexus\\Persistence\\EventSourced\\Effect';
    private const string EVENT_SOURCED_BEHAVIOR = 'Monadial\\Nexus\\Persistence\\EventSourced\\EventSourcedBehavior';
    private const string DURABLE_EFFECT = 'Monadial\\Nexus\\Persistence\\State\\DurableEffect';
    private const string DURABLE_STATE_BEHAVIOR = 'Monadial\\Nexus\\Persistence\\State\\DurableStateBehavior';
    private const string PERSISTENCE_ID = 'Monadial\\Nexus\\Persistence\\PersistenceId';
    private const string OVERRIDE = 'Override';
    private const string STD_CLASS = 'stdClass';

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
            'Generate a closure-based actor instead of an #[AsActor] handler (shorthand for --type=functional)',
        );
        $this->addOption(
            'type',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Actor type, one of: %s', implode(', ', self::TYPES)),
            'handler',
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $this->resolveType($input, $io);

        if ($type === null) {
            return Command::FAILURE;
        }

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

        $slug = lcfirst($name);
        $php = match ($type) {
            'handler' => self::buildHandler($name, $slug),
            'functional' => self::buildFunctional($name),
            'stateful' => self::buildStateful($name, $slug),
            'event-sourced' => self::buildEventSourced($name),
            'durable-state' => self::buildDurableState($name),
        };

        file_put_contents($file, (new PsrPrinter())->printFile($php));
        $io->success(sprintf('Created src/Actor/%sActor.php', $name));
        $this->printSpawnHint($io, $type, $name, $slug);

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

    /**
     * Resolve the effective actor type from --type and the --functional shorthand.
     *
     * Prints an error and returns null when the two options conflict or the
     * requested type is unknown.
     */
    private function resolveType(InputInterface $input, SymfonyStyle $io): ?string
    {
        $functional = $input->getOption('functional') === true;
        /** @var string $type */
        $type = $input->getOption('type');

        if ($functional) {
            if ($type !== 'handler' && $type !== 'functional') {
                $io->error(sprintf('Cannot combine --functional with --type=%s.', $type));

                return null;
            }

            return 'functional';
        }

        if (!in_array($type, self::TYPES, true)) {
            $io->error(sprintf('Unknown actor type "%s". Expected one of: %s.', $type, implode(', ', self::TYPES)));

            return null;
        }

        return $type;
    }

    private function printSpawnHint(SymfonyStyle $io, string $type, string $name, string $slug): void
    {
        if ($type === 'functional') {
            $io->writeln(sprintf(
                "Spawn it: \$system->spawn(Props::fromBehavior(%sActor::behavior()), '%s');",
                $name,
                $slug,
            ));

            return;
        }

        if ($type === 'event-sourced' || $type === 'durable-state') {
            $io->writeln(sprintf(
                "Spawn it: \$system->spawn(Props::fromBehavior(%sActor::behavior(\$id)), '%s-' . \$id);",
                $name,
                $slug,
            ));
            $io->note(
                'Requires: composer require nexus-actors/persistence (+ persistence-dbal/doctrine for real '
                . 'stores). These types are behavior factories the Kernel does not auto-spawn.',
            );
        }
    }

    /**
     * `#[AsActor]` handler — the Kernel auto-spawns it at boot.
     */
    private static function buildHandler(string $name, string $slug): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace(self::NS_ACTOR);
        $namespace->addUse(self::AS_ACTOR);
        $namespace->addUse(self::ACTOR_CONTEXT);
        $namespace->addUse(self::ACTOR_HANDLER);
        $namespace->addUse(self::BEHAVIOR);
        $namespace->addUse(self::OVERRIDE);

        $class = $namespace->addClass($name . 'Actor');
        $class->setFinal()->setReadOnly();
        $class->addImplement(self::ACTOR_HANDLER);
        $class->addComment('@implements ActorHandler<object>');
        $class->addAttribute(self::AS_ACTOR, [$slug]);

        $handle = $class->addMethod('handle');
        $handle->addAttribute(self::OVERRIDE);
        $handle->setReturnType(self::BEHAVIOR);
        $handle->addParameter('ctx')->setType(self::ACTOR_CONTEXT);
        $handle->addParameter('message')->setType('object');
        $handle->setBody(sprintf(<<<'PHP'
            return match (true) {
                // $message instanceof %sMessage => $this->on%s($ctx, $message),
                default => Behavior::unhandled(),
            };
            PHP, $name, $name));

        return $file;
    }

    /**
     * Closure-based actor — spawn manually via `Props::fromBehavior()`.
     */
    private static function buildFunctional(string $name): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace(self::NS_ACTOR);
        $namespace->addUse(self::ACTOR_CONTEXT);
        $namespace->addUse(self::BEHAVIOR);

        $class = $namespace->addClass($name . 'Actor');
        $class->setFinal();

        $behavior = $class->addMethod('behavior');
        $behavior->setStatic();
        $behavior->setReturnType(self::BEHAVIOR);
        $behavior->setBody(sprintf(<<<'PHP'
            return Behavior::receive(static function (ActorContext $ctx, object $message): Behavior {
                return match (true) {
                    // $message instanceof %sMessage => Behavior::same(),
                    default => Behavior::unhandled(),
                };
            });
            PHP, $name));

        return $file;
    }

    /**
     * `#[AsActor]` `StatefulActorHandler` — the Kernel auto-spawns it at boot.
     * State is a plain `int` counter starting at 0.
     */
    private static function buildStateful(string $name, string $slug): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace(self::NS_ACTOR);
        $namespace->addUse(self::AS_ACTOR);
        $namespace->addUse(self::ACTOR_CONTEXT);
        $namespace->addUse(self::BEHAVIOR_WITH_STATE);
        $namespace->addUse(self::STATEFUL_ACTOR_HANDLER);
        $namespace->addUse(self::OVERRIDE);

        $class = $namespace->addClass($name . 'Actor');
        $class->setFinal()->setReadOnly();
        $class->addImplement(self::STATEFUL_ACTOR_HANDLER);
        $class->addComment('@implements StatefulActorHandler<object, int>');
        $class->addAttribute(self::AS_ACTOR, [$slug]);

        $initialState = $class->addMethod('initialState');
        $initialState->addAttribute(self::OVERRIDE);
        $initialState->setReturnType('int');
        $initialState->setBody('return 0;');

        $handle = $class->addMethod('handle');
        $handle->addAttribute(self::OVERRIDE);
        $handle->setReturnType(self::BEHAVIOR_WITH_STATE);
        $handle->addParameter('ctx')->setType(self::ACTOR_CONTEXT);
        $handle->addParameter('message')->setType('object');
        $handle->addParameter('state')->setType('mixed');
        $handle->setBody(sprintf(<<<'PHP'
            return match (true) {
                // $message instanceof %sMessage => BehaviorWithState::next($state + 1),
                default => BehaviorWithState::same(),
            };
            PHP, $name));

        return $file;
    }

    /**
     * Event-sourced behavior factory — NOT an `#[AsActor]` handler. Spawn
     * manually per entity id via `Props::fromBehavior(...::behavior($id))`.
     */
    private static function buildEventSourced(string $name): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace(self::NS_ACTOR);
        $namespace->addUse(self::ACTOR_CONTEXT);
        $namespace->addUse(self::BEHAVIOR);
        $namespace->addUse(self::EFFECT);
        $namespace->addUse(self::EVENT_SOURCED_BEHAVIOR);
        $namespace->addUse(self::PERSISTENCE_ID);
        $namespace->addUse(self::STD_CLASS);

        $class = $namespace->addClass($name . 'Actor');
        $class->setFinal();
        $class->addComment(<<<COMMENT
            Event-sourced actor factory for {$name}.

            Not an #[AsActor] handler — the Kernel does not auto-spawn behavior
            factories. Spawn explicitly via Props::fromBehavior({$name}Actor::behavior(\$id)).

            The store must be wired via ->withEventStore() before ->toBehavior();
            on (re)start the persistence engine replays the entity's events to
            rebuild state before the first command is delivered.
            COMMENT);

        $behavior = $class->addMethod('behavior');
        $behavior->setStatic();
        $behavior->setReturnType(self::BEHAVIOR);
        $behavior->addParameter('id')->setType('string');
        $behavior->setBody(sprintf(<<<'PHP'
            return EventSourcedBehavior::create(
                PersistenceId::of('%s', $id),
                new stdClass(),
                static function (mixed $state, ActorContext $ctx, object $command): Effect {
                    return match (true) {
                        // $command instanceof SomeCommand => Effect::persist(new SomeEvent()),
                        default => Effect::none(),
                    };
                },
                static function (mixed $state, object $event): mixed {
                    return $state;
                },
            )
                // ->withEventStore($store) // wire an EventStore before spawning
                ->toBehavior();
            PHP, $name));

        return $file;
    }

    /**
     * Durable-state behavior factory — NOT an `#[AsActor]` handler. Spawn
     * manually per entity id via `Props::fromBehavior(...::behavior($id))`.
     */
    private static function buildDurableState(string $name): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace(self::NS_ACTOR);
        $namespace->addUse(self::ACTOR_CONTEXT);
        $namespace->addUse(self::BEHAVIOR);
        $namespace->addUse(self::DURABLE_EFFECT);
        $namespace->addUse(self::DURABLE_STATE_BEHAVIOR);
        $namespace->addUse(self::PERSISTENCE_ID);
        $namespace->addUse(self::STD_CLASS);

        $class = $namespace->addClass($name . 'Actor');
        $class->setFinal();
        $class->addComment(<<<COMMENT
            Durable-state actor factory for {$name}.

            Not an #[AsActor] handler — the Kernel does not auto-spawn behavior
            factories. Spawn explicitly via Props::fromBehavior({$name}Actor::behavior(\$id)).

            The store must be wired via ->withStateStore() before ->toBehavior();
            on (re)start the persistence engine loads the latest persisted state
            to rebuild state before the first command is delivered.
            COMMENT);

        $behavior = $class->addMethod('behavior');
        $behavior->setStatic();
        $behavior->setReturnType(self::BEHAVIOR);
        $behavior->addParameter('id')->setType('string');
        $behavior->setBody(sprintf(<<<'PHP'
            return DurableStateBehavior::create(
                PersistenceId::of('%s', $id),
                new stdClass(),
                static function (mixed $state, ActorContext $ctx, object $command): DurableEffect {
                    return match (true) {
                        // $command instanceof SomeCommand => DurableEffect::persist($state),
                        default => DurableEffect::none(),
                    };
                },
            )
                // ->withStateStore($store) // wire a DurableStateStore before spawning
                ->toBehavior();
            PHP, $name));

        return $file;
    }
}
