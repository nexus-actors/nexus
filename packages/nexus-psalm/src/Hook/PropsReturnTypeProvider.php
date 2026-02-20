<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class PropsReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    private const array TARGET_INTERFACES = [ActorHandler::class, StatefulActorHandler::class];

    /** @return array<string> */
    public static function getClassLikeNames(): array
    {
        return [Props::class];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        return match ($event->getMethodNameLowercase()) {
            'fromcontainer' => self::handleFromContainer($event),
            'fromfactory', 'fromstatefulfactory' => self::handleFromFactory($event),
            default => null,
        };
    }

    private static function handleFromContainer(MethodReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();

        if (\count($args) < 2) {
            return null;
        }

        $classArgType = $event->getSource()->getNodeTypeProvider()->getType($args[1]->value);

        if ($classArgType === null) {
            return null;
        }

        foreach ($classArgType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TClassString && $atomic->as_type instanceof TGenericObject) {
                $messageType = self::extractFromGenericObject($atomic->as_type);

                if ($messageType !== null) {
                    return self::makePropsUnion($messageType);
                }
            }

            if ($atomic instanceof TLiteralClassString) {
                $messageType = self::extractFromStorage($event->getSource()->getCodebase(), $atomic->value);

                if ($messageType !== null) {
                    return self::makePropsUnion($messageType);
                }
            }
        }

        return null;
    }

    private static function handleFromFactory(MethodReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return null;
        }

        $callableType = $event->getSource()->getNodeTypeProvider()->getType($args[0]->value);

        if ($callableType === null) {
            return null;
        }

        foreach ($callableType->getAtomicTypes() as $atomic) {
            $returnType = self::getCallableReturnType($atomic);

            if ($returnType === null) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $returnAtomic) {
                if (!$returnAtomic instanceof TNamedObject) {
                    continue;
                }

                // Direct generic: callable(): ActorHandler<T>
                if ($returnAtomic instanceof TGenericObject) {
                    $messageType = self::extractFromGenericObject($returnAtomic);

                    if ($messageType !== null) {
                        return self::makePropsUnion($messageType);
                    }
                }

                // Concrete class: callable(): MyHandler — look up storage
                $messageType = self::extractFromStorage(
                    $event->getSource()->getCodebase(),
                    $returnAtomic->value,
                );

                if ($messageType !== null) {
                    return self::makePropsUnion($messageType);
                }
            }
        }

        return null;
    }

    private static function getCallableReturnType(mixed $atomic): ?Union
    {
        if ($atomic instanceof TClosure) {
            return $atomic->return_type;
        }

        if ($atomic instanceof TCallable) {
            return $atomic->return_type;
        }

        return null;
    }

    private static function extractFromGenericObject(TGenericObject $type): ?Union
    {
        foreach (self::TARGET_INTERFACES as $interface) {
            if (\strcasecmp($type->value, $interface) === 0 && isset($type->type_params[0])) {
                return $type->type_params[0];
            }
        }

        return null;
    }

    private static function extractFromStorage(Codebase $codebase, string $fqcn): ?Union
    {
        if (!$codebase->classlike_storage_provider->has($fqcn)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($fqcn);
        $extendedParams = $storage->template_extended_params;

        if ($extendedParams === null) {
            return null;
        }

        foreach (self::TARGET_INTERFACES as $interface) {
            if (isset($extendedParams[$interface])) {
                $params = $extendedParams[$interface];
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
