<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\WithStateBehavior;
use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Union;

use function count;

/**
 * @psalm-api
 *
 * Infers `WithStateBehavior<T, S>` from the handler closure passed to
 * `Behavior::withState(...)`.
 *
 * The existing docblock on `Behavior::withState()` declares
 *
 *     @template U of object
 *     @template S
 *     @param S $initialState
 *     @param Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
 *     @return WithStateBehavior<U, S>
 *
 * but Psalm doesn't reliably back-propagate U/S from the closure body, so
 * call sites see `WithStateBehavior<object, mixed>`. This hook extracts
 * the closure's second parameter (message type, U) and third parameter
 * (state type, S) and rewrites the return to a properly narrowed generic.
 *
 * Example:
 *
 *     $b = Behavior::withState(
 *         0,
 *         static fn(ActorContext $ctx, Increment $msg, int $count) => ...,
 *     );
 *     // Without hook: WithStateBehavior<object, mixed>
 *     // With hook:    WithStateBehavior<Increment, int>
 *
 * Partial inference is supported: a typed state with an `object` message
 * still returns `WithStateBehavior<object, int>` (narrower than the
 * default). If the closure shape can't be inspected at all, the hook
 * falls through to Psalm's default.
 */
final class BehaviorWithStateReturnTypeProvider implements MethodReturnTypeProviderInterface
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
        if ($event->getMethodNameLowercase() !== 'withstate') {
            return null;
        }

        $args = $event->getCallArgs();

        if (count($args) < 2) {
            return null;
        }

        // withState($initialState, $handler) — closure is the SECOND arg.
        $closureType = $event->getSource()->getNodeTypeProvider()->getType($args[1]->value);

        if ($closureType === null) {
            return null;
        }

        if (count($closureType->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $closureType->getSingleAtomic();

        if (!$atomic instanceof TClosure) {
            return null;
        }

        $params = $atomic->params;

        if ($params === null || count($params) < 3) {
            return null;
        }

        $messageGeneric = self::resolveMessageGeneric($params[1]->type);
        $stateGeneric = $params[2]->type;

        if ($stateGeneric === null) {
            return null;
        }

        return new Union([
            new TGenericObject(WithStateBehavior::class, [$messageGeneric, $stateGeneric]),
        ]);
    }

    /**
     * Resolve the WithStateBehavior `T` (message) generic from the closure's
     * second parameter. Falls back to `object` when the param is missing,
     * untyped, a union, or already `object` — keeping the existing receive
     * hook's behavior: only narrow when we have a single concrete class.
     */
    private static function resolveMessageGeneric(?Union $messageParamType): Union
    {
        // Docblock `object` parses to TObject, not TNamedObject('object') —
        // use it for the fallback so the inferred generic structurally
        // matches user-declared `WithStateBehavior<object, ...>` types.
        $objectFallback = new Union([new TObject()]);

        if ($messageParamType === null) {
            return $objectFallback;
        }

        if (count($messageParamType->getAtomicTypes()) !== 1) {
            return $objectFallback;
        }

        $atomic = $messageParamType->getSingleAtomic();

        if ($atomic instanceof TObject) {
            return $objectFallback;
        }

        if (!$atomic instanceof TNamedObject) {
            return $objectFallback;
        }

        return new Union([new TNamedObject($atomic->value)]);
    }
}
