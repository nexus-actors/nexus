<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Union;

final class PropsFromContainerReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /** @return array<string> */
    public static function getClassLikeNames(): array
    {
        return [Props::class];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'fromcontainer') {
            return null;
        }

        $args = $event->getCallArgs();

        if (count($args) < 2) {
            return null;
        }

        $classArgType = $event->getSource()->getNodeTypeProvider()->getType($args[1]->value);

        if ($classArgType === null) {
            return null;
        }

        foreach ($classArgType->getAtomicTypes() as $atomic) {
            // Case A: class-string<ActorHandler<T>> — generic class-string with as_type
            if ($atomic instanceof TClassString && $atomic->as_type instanceof TGenericObject) {
                $messageType = self::extractMessageTypeFromGenericObject($atomic->as_type);

                if ($messageType !== null) {
                    return self::makePropsUnion($messageType);
                }
            }

            // Case B: literal class-string like MyHandler::class — look up template_extended_params
            if ($atomic instanceof TLiteralClassString) {
                $messageType = self::extractMessageTypeFromStorage($event, $atomic->value);

                if ($messageType !== null) {
                    return self::makePropsUnion($messageType);
                }
            }
        }

        return null;
    }

    private static function extractMessageTypeFromGenericObject(TGenericObject $asType): ?Union
    {
        $targetInterfaces = [ActorHandler::class, StatefulActorHandler::class];

        foreach ($targetInterfaces as $interface) {
            if (strcasecmp($asType->value, $interface) === 0 && isset($asType->type_params[0])) {
                return $asType->type_params[0];
            }
        }

        return null;
    }

    private static function extractMessageTypeFromStorage(MethodReturnTypeProviderEvent $event, string $fqcn): ?Union
    {
        $codebase = $event->getSource()->getCodebase();

        if (!$codebase->classlike_storage_provider->has($fqcn)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($fqcn);
        $extendedParams = $storage->template_extended_params;

        if ($extendedParams === null) {
            return null;
        }

        $targetInterfaces = [ActorHandler::class, StatefulActorHandler::class];

        foreach ($targetInterfaces as $interface) {
            if (isset($extendedParams[$interface])) {
                $params = $extendedParams[$interface];
                // First template parameter is T (the message type)
                $first = reset($params);

                if ($first instanceof Union) {
                    return $first;
                }
            }
        }

        return null;
    }

    private static function makePropsUnion(Union $messageType): Union
    {
        return new Union([
            new TGenericObject(Props::class, [$messageType]),
        ]);
    }
}
