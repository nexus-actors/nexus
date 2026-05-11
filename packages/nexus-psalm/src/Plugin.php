<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm;

use Monadial\Nexus\Psalm\Hook\Aggregate\AggregateEmitsOnlyEventsRule;
use Monadial\Nexus\Psalm\Hook\Aggregate\AggregateRepositoryReadOnlyBulkRule;
use Monadial\Nexus\Psalm\Hook\Aggregate\FactoryAssignsOnlyIdRule;
use Monadial\Nexus\Psalm\Hook\BehaviorSubclassNarrowingHook;
use Monadial\Nexus\Psalm\Hook\BlockingCallInHandlerRule;
use Monadial\Nexus\Psalm\Hook\Bus\AuthorizeBeforeValidationRule;
use Monadial\Nexus\Psalm\Hook\Bus\CommandHandlerReturnTypeRule;
use Monadial\Nexus\Psalm\Hook\Bus\CommandReturnValueIgnoredRule;
use Monadial\Nexus\Psalm\Hook\Bus\IdempotencyKeyFieldExistsRule;
use Monadial\Nexus\Psalm\Hook\Bus\UnguardedExternalSideEffectRule;
use Monadial\Nexus\Psalm\Hook\Bus\ValidatedCommandReadonlyRule;
use Monadial\Nexus\Psalm\Hook\CloneWithReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\Messaging\CommandHandlerSignatureRule;
use Monadial\Nexus\Psalm\Hook\Messaging\EventListenerSignatureRule;
use Monadial\Nexus\Psalm\Hook\Messaging\OneCommandHandlerRule;
use Monadial\Nexus\Psalm\Hook\Messaging\QueryHandlerSignatureRule;
use Monadial\Nexus\Psalm\Hook\Messaging\ReadonlyMessageBodyRule;
use Monadial\Nexus\Psalm\Hook\MutableActorStateRule;
use Monadial\Nexus\Psalm\Hook\MutableClosureCaptureRule;
use Monadial\Nexus\Psalm\Hook\NonSerializableRemoteMessageRule;
use Monadial\Nexus\Psalm\Hook\PropsReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\ReadonlyMessageRule;
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
            BlockingCallInHandlerRule::class,
            MutableClosureCaptureRule::class,
            PropsReturnTypeProvider::class,
            CloneWithReturnTypeProvider::class,
            ReadonlyMessageBodyRule::class,
            CommandHandlerSignatureRule::class,
            QueryHandlerSignatureRule::class,
            EventListenerSignatureRule::class,
            OneCommandHandlerRule::class,
            FactoryAssignsOnlyIdRule::class,
            AggregateEmitsOnlyEventsRule::class,
            AggregateRepositoryReadOnlyBulkRule::class,
            CommandHandlerReturnTypeRule::class,
            CommandReturnValueIgnoredRule::class,
            ValidatedCommandReadonlyRule::class,
            IdempotencyKeyFieldExistsRule::class,
            AuthorizeBeforeValidationRule::class,
            UnguardedExternalSideEffectRule::class,
        ];

        foreach ($hooks as $hook) {
            if (class_exists($hook)) {
                $registration->registerHooksFromClass($hook);
            }
        }
    }
}
