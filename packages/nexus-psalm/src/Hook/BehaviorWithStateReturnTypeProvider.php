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
use Psalm\Type\Atomic\TMixed;
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

        $narrowMessage = self::tryNarrowMessage($params[1]->type);
        $narrowState = self::tryNarrowState($params[2]->type);

        // CRITICAL: when BOTH params are wide (object/mixed/untyped), fall
        // through to Psalm's natural template inference. Otherwise the hook
        // hard-codes WithStateBehavior<object, mixed> and breaks outer
        // template bindings — see Props::fromStatefulFactory where the
        // inner closure `(ActorContext, object $msg, mixed $state)` must
        // bind U/S from the surrounding `@template U of object; @template S`
        // context on fromStatefulFactory itself.
        if ($narrowMessage === null && $narrowState === null) {
            return null;
        }

        $messageGeneric = $narrowMessage ?? new Union([new TObject()]);
        $stateGeneric = $narrowState ?? new Union([new TMixed()]);

        return new Union([
            new TGenericObject(WithStateBehavior::class, [$messageGeneric, $stateGeneric]),
        ]);
    }

    /**
     * Try to narrow the message generic from the closure's second parameter.
     * Returns a single-class Union if the param is a concrete named class,
     * or null when the param is wide (object / union / untyped) — caller
     * decides whether to fall through entirely or substitute a fallback.
     */
    private static function tryNarrowMessage(?Union $messageParamType): ?Union
    {
        if ($messageParamType === null) {
            return null;
        }

        if (count($messageParamType->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $messageParamType->getSingleAtomic();

        // TObject (docblock `object` keyword) is wide — not narrowable.
        if ($atomic instanceof TObject) {
            return null;
        }

        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        return new Union([new TNamedObject($atomic->value)]);
    }

    /**
     * Try to narrow the state generic from the closure's third parameter.
     * Returns the param's full Union verbatim, or null when the param is
     * absent or annotated as bare `mixed` — `mixed` carries no information
     * and should be treated as a fall-through signal, not a binding.
     */
    private static function tryNarrowState(?Union $stateParamType): ?Union
    {
        if ($stateParamType === null) {
            return null;
        }

        if (count($stateParamType->getAtomicTypes()) === 1 && $stateParamType->getSingleAtomic() instanceof TMixed) {
            return null;
        }

        return $stateParamType;
    }
}
