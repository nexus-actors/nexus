<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ReceiveBehavior;
use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function count;

/**
 * @psalm-api
 *
 * Infers `ReceiveBehavior<T>` from the message-parameter type of the
 * handler closure passed to `Behavior::receive(...)`.
 *
 * The existing docblock on `Behavior::receive()` declares
 *
 *     @template U of object
 *     @param Closure(ActorContext<U>, U): Behavior<U> $handler
 *     @return ReceiveBehavior<U>
 *
 * but Psalm's template-from-closure-param inference doesn't reliably
 * propagate U out — at the call site users see `ReceiveBehavior<object>`
 * even when their closure takes a specific message type. This hook reads
 * the closure's second parameter type at the call site and rewrites the
 * return to `ReceiveBehavior<MessageClass>`.
 *
 * Example:
 *
 *     $b = Behavior::receive(
 *         static fn(ActorContext $ctx, GetOrder $msg): Behavior => Behavior::same(),
 *     );
 *     // Without hook: ReceiveBehavior<object>
 *     // With hook:    ReceiveBehavior<GetOrder>
 *
 * The hook only fires when the closure's second parameter is a single
 * named class. The bare `object` keyword (parsed as TObject, rejected by
 * the `!instanceof TNamedObject` guard), unions, and untyped params all
 * fall through to Psalm's default behavior (typically
 * ReceiveBehavior<object>).
 */
final class BehaviorReceiveReturnTypeProvider implements MethodReturnTypeProviderInterface
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
        if ($event->getMethodNameLowercase() !== 'receive') {
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
            new TGenericObject(ReceiveBehavior::class, [new Union([new TNamedObject($messageClass)])]),
        ]);
    }

    /**
     * Walk the closure type for a single concrete named class on its
     * second parameter (the message; first is ActorContext).
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

        if ($params === null || count($params) < 2) {
            return null;
        }

        $messageParam = $params[1];
        $paramType = $messageParam->type;

        if ($paramType === null) {
            return null;
        }

        if (count($paramType->getAtomicTypes()) !== 1) {
            return null;
        }

        $paramAtomic = $paramType->getSingleAtomic();

        // Psalm parses the `object` keyword as a TObject atomic, not as
        // TNamedObject('object'), so this guard also rejects bare `object`.
        // The only way the next return could surface "object" is if a user
        // class were literally named `object`, which PHP disallows.
        if (!$paramAtomic instanceof TNamedObject) {
            return null;
        }

        return $paramAtomic->value;
    }
}
