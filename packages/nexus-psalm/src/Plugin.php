<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm;

use Monadial\Nexus\Psalm\Hook\MutableActorStateRule;
use Monadial\Nexus\Psalm\Hook\PropsFromContainerReturnTypeProvider;
use Monadial\Nexus\Psalm\Hook\ReadonlyMessageRule;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use function class_exists;

final class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $hooks = [
            ReadonlyMessageRule::class,
            MutableActorStateRule::class,
            PropsFromContainerReturnTypeProvider::class,
        ];

        foreach ($hooks as $hook) {
            if (class_exists($hook)) {
                $registration->registerHooksFromClass($hook);
            }
        }
    }
}
