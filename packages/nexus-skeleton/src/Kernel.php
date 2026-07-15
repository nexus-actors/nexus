<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\AsActorPass;
use Monadial\Nexus\App\ActorRegistry;
use Monadial\Nexus\App\AsActor;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class Kernel
{
    private ?ContainerInterface $container = null;

    /**
     * @var array<string, ActorRef<object>>
     * @psalm-suppress UntypedActorRefInjection this is a heterogeneous registry of every spawned actor, so ActorRef<object> is correct
     */
    private array $refs = [];

    public function __construct(private readonly string $projectDir, private readonly string $appName = 'my-app') {}

    public function container(): ContainerInterface
    {
        return $this->container ??= $this->buildContainer();
    }

    /**
     * Create the ActorSystem and spawn every #[AsActor] handler, returning it un-run.
     */
    public function boot(): ActorSystem
    {
        $container = $this->container();

        /** @var ActorRegistry $registry */
        $registry = $container->get(ActorRegistry::class);
        /** @var Runtime $runtime */
        $runtime = $container->get('nexus.runtime');

        $system = ActorSystem::create($this->appName, $runtime);

        foreach ($registry->all() as $name => $class) {
            /** @psalm-suppress ArgumentTypeCoercion registry only ever holds ActorHandler class-strings (enforced by #[AsActor] autoconfiguration) */
            $this->refs[$name] = $system->spawn(Props::fromContainer($container, $class), $name);
        }

        return $system;
    }

    public function run(): void
    {
        $this->boot()->run();
    }

    /**
     * Reference to a spawned top-level actor by its #[AsActor] name, or null if none was spawned.
     *
     * @return ActorRef<object>|null
     */
    public function ref(string $name): ?ActorRef
    {
        return $this->refs[$name] ?? null;
    }

    private function buildContainer(): ContainerInterface
    {
        $container = new ContainerBuilder();

        // ActorRegistry is a public, shared service the Kernel reads after compile.
        $container->register(ActorRegistry::class, ActorRegistry::class)->setPublic(true);

        // Runtime comes from config/packages/runtime.php (a Closure returning a Runtime).
        // The container can't dump a Closure factory, so we invoke it now and inject the
        // instance as a synthetic service (this container is never PHP-dumped).
        /** @var callable(): Runtime $runtimeFactory */
        $runtimeFactory = require $this->projectDir . '/config/packages/runtime.php';
        $runtime = $runtimeFactory();
        $container->register('nexus.runtime', Runtime::class)
            ->setSynthetic(true)
            ->setPublic(true);

        // Autoconfigure: every #[AsActor] handler is tagged, made non-shared (fresh per spawn), and public.
        $container->registerAttributeForAutoconfiguration(
            AsActor::class,
            static function (ChildDefinition $definition, AsActor $attribute): void {
                $definition->addTag('nexus.actor', ['name' => $attribute->name]);
                $definition->setShared(false);
                $definition->setPublic(true);
            },
        );

        $loader = new PhpFileLoader($container, new FileLocator($this->projectDir . '/config'));
        $loader->load('services.php');

        $container->addCompilerPass(new AsActorPass());
        $container->compile();

        // Synthetic services must be set on the compiled container.
        $container->set('nexus.runtime', $runtime);

        return $container;
    }
}
