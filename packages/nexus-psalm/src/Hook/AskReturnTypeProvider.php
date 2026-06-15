<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Runtime\Async\Future;
use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function count;
use function strcasecmp;

/**
 * @psalm-api
 *
 * Rewrites the return type of `ActorRef::ask()` from `Future<R>` (where R
 * is template-unconstrained) to `Future<ReplyClass>` when the message
 * passed as the first argument carries a `#[ReplyType(ReplyClass::class)]`
 * attribute.
 *
 * No runtime impact. Static-analysis only.
 *
 * Without this hook:
 *
 *   #[ReplyType(Order::class)]
 *   final readonly class GetOrder { public function __construct(public string $id) {} }
 *
 *   $order = $orders->ask(new GetOrder('42'), Duration::seconds(2))->await();
 *   // Psalm sees: Future<R> where R is unbound → ->await() returns object|mixed
 *
 * With this hook:
 *
 *   $order = $orders->ask(new GetOrder('42'), Duration::seconds(2))->await();
 *   // Psalm infers: Future<Order> → ->await() returns Order
 *
 * Uses Psalm's own classlike_storage_provider to read attribute metadata
 * — runtime reflection would require the fixture/message classes to be
 * autoloadable in Psalm's process, which is not guaranteed.
 */
final class AskReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /** @return array<string> */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return [ActorRef::class];
    }

    #[Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'ask') {
            return null;
        }

        $args = $event->getCallArgs();

        if (count($args) < 1) {
            return null;
        }

        $messageType = $event->getSource()->getNodeTypeProvider()->getType($args[0]->value);

        if ($messageType === null) {
            return null;
        }

        $replyClass = self::extractReplyType($event, $messageType);

        if ($replyClass === null) {
            return null;
        }

        return new Union([
            new TGenericObject(Future::class, [new Union([new TNamedObject($replyClass)])]),
        ]);
    }

    /**
     * Walk the message Union for a single concrete class carrying
     * `#[ReplyType]`. Returns null on union mismatch, unknown class, or
     * missing attribute.
     */
    private static function extractReplyType(MethodReturnTypeProviderEvent $event, Union $messageType): ?string
    {
        if (count($messageType->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = $messageType->getSingleAtomic();

        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        $messageClass = $atomic->value;
        $codebase = $event->getSource()->getCodebase();

        if (!$codebase->classlikes->classExists($messageClass)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($messageClass);

        foreach ($storage->attributes as $attribute) {
            if (strcasecmp($attribute->fq_class_name, ReplyType::class) !== 0) {
                continue;
            }

            $arg = $attribute->args[0] ?? null;

            if ($arg === null) {
                return null;
            }

            $argType = $arg->type;

            if (!$argType instanceof Union) {
                return null;
            }

            if (count($argType->getAtomicTypes()) !== 1) {
                return null;
            }

            $argAtomic = $argType->getSingleAtomic();

            if (!$argAtomic instanceof TLiteralClassString) {
                return null;
            }

            return $argAtomic->value;
        }

        return null;
    }
}
