<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm;

use Monadial\Nexus\Psalm\Hook\BlockingCallInHandlerRule;
use Monadial\Nexus\Psalm\Hook\CloneWithReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\MutableActorStateRule;
use Monadial\Nexus\Psalm\Hook\MutableClosureCaptureRule;
use Monadial\Nexus\Psalm\Hook\NonSerializableClusterMessageRule;
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
            ReadonlyMessageRule::class,
            MutableActorStateRule::class,
            NonSerializableClusterMessageRule::class,
            BlockingCallInHandlerRule::class,
            MutableClosureCaptureRule::class,
            PropsReturnTypeProvider::class,
            CloneWithReturnTypeProvider::class,
        ];

        foreach ($hooks as $hook) {
            if (class_exists($hook)) {
                $registration->registerHooksFromClass($hook);
            }
        }
    }
}
