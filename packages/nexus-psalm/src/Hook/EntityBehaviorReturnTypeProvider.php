<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Union;

use function count;

/**
 * @psalm-api
 *
 * Infers `EntityBehaviorBuilder<T, C>` from the arguments passed to
 * `EntityBehavior::create($entityClass, $id, $commandHandler)`.
 *
 * The existing docblock on `EntityBehavior::create()` declares
 *
 *     @template T of object
 *     @template C of object
 *     @param class-string<T> $entityClass
 *     @param Closure(ActorContext<C>, C, T): EntityEffect<T> $commandHandler
 *     @return EntityBehaviorBuilder<T, C>
 *
 * but Psalm's template inference from class-string literals and nested
 * closure parameters doesn't reliably propagate T and C out — at the call
 * site users see `EntityBehaviorBuilder<object, object>`. This hook reads:
 * - arg[0]: the literal class-string to extract T
 * - arg[2]: the closure's second parameter type to extract C
 *
 * and rewrites the return to `EntityBehaviorBuilder<T, C>`.
 *
 * Example:
 *
 *     $b = EntityBehavior::create(
 *         entityClass: Order::class,
 *         id: $id,
 *         commandHandler: static fn(ActorContext $ctx, AddItem $cmd, Order $o): EntityEffect => EntityEffect::same(),
 *     );
 *     // Without hook: EntityBehaviorBuilder<object, object>
 *     // With hook:    EntityBehaviorBuilder<Order, AddItem>
 *
 * When either generic cannot be narrowed (e.g. the class-string is a
 * variable or the closure param is untyped), the hook falls through to
 * Psalm's default inference.
 */
final class EntityBehaviorReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /** @return array<string> */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return [EntityBehavior::class];
    }

    #[Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'create') {
            return null;
        }

        $args = $event->getCallArgs();

        if (count($args) < 3) {
            return null;
        }

        $source = $event->getSource();

        // arg[0] = $entityClass (class-string<T>) → extract T
        $entityClassType = $source->getNodeTypeProvider()->getType($args[0]->value);

        if ($entityClassType === null) {
            return null;
        }

        $entityClass = self::extractLiteralClassString($entityClassType);

        if ($entityClass === null) {
            return null;
        }

        // arg[2] = $commandHandler (Closure(ActorContext<C>, C, T): EntityEffect<T>) → extract C
        $closureType = $source->getNodeTypeProvider()->getType($args[2]->value);

        if ($closureType === null) {
            return null;
        }

        $commandClass = self::extractClosureSecondParamClass($closureType);

        if ($commandClass === null) {
            return null;
        }

        return new Union([
            new TGenericObject(EntityBehaviorBuilder::class, [
                new Union([new TNamedObject($entityClass)]),
                new Union([new TNamedObject($commandClass)]),
            ]),
        ]);
    }

    /**
     * Extract the literal class name from a class-string<T> Union.
     * Returns null if the type is not a single TLiteralClassString.
     */
    private static function extractLiteralClassString(Union $type): ?string
    {
        if (count($type->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $type->getSingleAtomic();

        if (!$atomic instanceof TLiteralClassString) {
            return null;
        }

        return $atomic->value;
    }

    /**
     * Extract the concrete named class from the closure's second parameter
     * (index 1 — the command/message type C). Returns null when the param
     * is absent, untyped, or wide (bare `object`).
     */
    private static function extractClosureSecondParamClass(Union $closureType): ?string
    {
        if (count($closureType->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $closureType->getSingleAtomic();

        if (!$atomic instanceof TClosure) {
            return null;
        }

        $params = $atomic->params;

        if ($params === null || count($params) < 2) {
            return null;
        }

        $commandParam = $params[1];
        $paramType = $commandParam->type;

        if ($paramType === null) {
            return null;
        }

        if (count($paramType->getAtomicTypes()) !== 1) {
            return null;
        }

        $paramAtomic = $paramType->getSingleAtomic();

        // Bare `object` keyword is wide — not narrowable.
        if ($paramAtomic instanceof TObject) {
            return null;
        }

        if (!$paramAtomic instanceof TNamedObject) {
            return null;
        }

        return $paramAtomic->value;
    }
}
