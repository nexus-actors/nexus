<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm;

use Monadial\Nexus\Psalm\Hook\AskReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\BehaviorReceiveReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\BehaviorSetupReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\BehaviorSubclassNarrowingHook;
use Monadial\Nexus\Psalm\Hook\BehaviorWithStateReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\BlockingCallInHandlerRule;
use Monadial\Nexus\Psalm\Hook\CloneWithReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\EntityBehaviorReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\MismatchedReplyTypeRule;
use Monadial\Nexus\Psalm\Hook\MissingTransactionalDeclarationRule;
use Monadial\Nexus\Psalm\Hook\MutableActorStateRule;
use Monadial\Nexus\Psalm\Hook\MutableClosureCaptureRule;
use Monadial\Nexus\Psalm\Hook\NonSerializableRemoteMessageRule;
use Monadial\Nexus\Psalm\Hook\PooledConnectionInActorPropertyRule;
use Monadial\Nexus\Psalm\Hook\PropsReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\ReadonlyMessageRule;
use Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule;
use Monadial\Nexus\Psalm\Hook\UntypedActorRefPropertyRule;
use Override;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

use function class_exists;

/** @psalm-api */
final class Plugin implements PluginEntryPointInterface
{
    #[Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $hooks = [
            BehaviorSubclassNarrowingHook::class,
            ReadonlyMessageRule::class,
            MutableActorStateRule::class,
            NonSerializableRemoteMessageRule::class,
            MissingTransactionalDeclarationRule::class,
            PooledConnectionInActorPropertyRule::class,
            BlockingCallInHandlerRule::class,
            MutableClosureCaptureRule::class,
            UntypedActorRefInjectionRule::class,
            UntypedActorRefPropertyRule::class,
            PropsReturnTypeProvider::class,
            CloneWithReturnTypeProvider::class,
            AskReturnTypeProvider::class,
            BehaviorReceiveReturnTypeProvider::class,
            BehaviorSetupReturnTypeProvider::class,
            BehaviorWithStateReturnTypeProvider::class,
            EntityBehaviorReturnTypeProvider::class,
            MismatchedReplyTypeRule::class,
        ];

        foreach ($hooks as $hook) {
            if (class_exists($hook)) {
                $registration->registerHooksFromClass($hook);
            }
        }
    }
}
