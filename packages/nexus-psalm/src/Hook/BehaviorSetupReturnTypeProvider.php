<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\SetupBehavior;
use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function count;
use function strcasecmp;

/**
 * @psalm-api
 *
 * Infers `SetupBehavior<T>` from the `ActorContext<T>` generic on the
 * setup-factory closure.
 *
 * Unlike `receive` and `withState`, `setup`'s closure has no message
 * parameter — U is encoded entirely in the ActorContext generic:
 *
 *     @template U of object
 *     @param Closure(ActorContext<U>): Behavior<U> $factory
 *     @return SetupBehavior<U>
 *
 * So this hook peers INTO the first closure parameter (`ActorContext<U>`)
 * and lifts its template argument out as the SetupBehavior generic.
 *
 * Example:
 *
 *     $b = Behavior::setup(
 *         static fn(ActorContext $ctx): Behavior => Behavior::receive(
 *             static fn(ActorContext $c, GetOrder $msg): Behavior => Behavior::same(),
 *         ),
 *     );
 *     // Only fires when the closure explicitly types ActorContext<GetOrder>.
 *     // Untyped ActorContext (no generic) falls through to Psalm's default.
 *
 * Only fires when the closure's first parameter is a TGenericObject for
 * `ActorContext` whose single template argument is a concrete named class.
 * Falls through for bare `ActorContext` (no generic), unions, or the
 * bare `object` keyword (parsed as TObject, rejected by the
 * `!instanceof TNamedObject` guard) — never widens past Psalm's default.
 */
final class BehaviorSetupReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /** @return array<string> */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return [Behavior::class];
    }

    #[Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'setup') {
            return null;
        }

        $args = $event->getCallArgs();

        if (count($args) < 1) {
            return null;
        }

        $closureType = $event->getSource()->getNodeTypeProvider()->getType($args[0]->value);

        if ($closureType === null) {
            return null;
        }

        $messageClass = self::extractMessageClass($closureType);

        if ($messageClass === null) {
            return null;
        }

        return new Union([
            new TGenericObject(SetupBehavior::class, [new Union([new TNamedObject($messageClass)])]),
        ]);
    }

    /**
     * Walk the closure → first param → ActorContext generic argument →
     * single TNamedObject. Returns the class name or null to fall through.
     */
    private static function extractMessageClass(Union $closureType): ?string
    {
        if (count($closureType->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $closureType->getSingleAtomic();

        if (!$atomic instanceof TClosure) {
            return null;
        }

        $params = $atomic->params;

        if ($params === null || count($params) < 1) {
            return null;
        }

        $contextParam = $params[0];
        $paramType = $contextParam->type;

        if ($paramType === null) {
            return null;
        }

        if (count($paramType->getAtomicTypes()) !== 1) {
            return null;
        }

        $contextAtomic = $paramType->getSingleAtomic();

        // Must be a generic ActorContext<...> — bare TNamedObject lacks the
        // template argument we need.
        if (!$contextAtomic instanceof TGenericObject) {
            return null;
        }

        if (strcasecmp($contextAtomic->value, ActorContext::class) !== 0) {
            return null;
        }

        if (count($contextAtomic->type_params) < 1) {
            return null;
        }

        $messageGeneric = $contextAtomic->type_params[0];

        if (count($messageGeneric->getAtomicTypes()) !== 1) {
            return null;
        }

        $messageAtomic = $messageGeneric->getSingleAtomic();

        // Psalm parses the `object` keyword as a TObject atomic, not as
        // TNamedObject('object'), so this guard also rejects bare `object`.
        // The only way the next return could surface "object" is if a user
        // class were literally named `object`, which PHP disallows.
        if (!$messageAtomic instanceof TNamedObject) {
            return null;
        }

        return $messageAtomic->value;
    }
}
