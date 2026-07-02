<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Psalm\Issue\MismatchedReplyType;
use Override;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function array_key_first;
use function count;
use function implode;
use function strcasecmp;
use function strtolower;

/**
 * @psalm-api
 *
 * Flags `ActorContext::reply($x)` calls where the message currently being
 * handled has a `#[ReplyType(Expected::class)]` attribute but `$x` is not
 * an instance of `Expected`.
 *
 * Finding the "current message":
 *
 *   At a reply() call site, this hook walks vars_in_scope looking for
 *   variables whose narrowed type is a single concrete class carrying
 *   `#[ReplyType]`. If EXACTLY ONE such variable is in scope, it's
 *   treated as the active message and the rule fires. If zero or more
 *   than one qualify, the rule silently skips — we'd rather miss a real
 *   mismatch than false-positive on legitimate code.
 *
 * Works with the canonical `match (true) { $msg instanceof X => ... }`
 * pattern AND the typed-parameter pattern (`function (ActorContext $ctx,
 * GetOrder $msg)`).
 *
 * Does NOT flag if the reply value is itself a union that INCLUDES the
 * expected type — e.g. `$ctx->reply($maybeOrder)` where `$maybeOrder` is
 * `Order|null` is allowed (Psalm's normal type-checker will catch the
 * null case if reply()'s signature requires non-null).
 */
final class MismatchedReplyTypeRule implements AfterMethodCallAnalysisInterface
{
    private const string REPLY_METHOD_ID = 'monadial\nexus\core\actor\actorcontext::reply';

    #[Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        if (strtolower($event->getDeclaringMethodId()) !== self::REPLY_METHOD_ID) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if (!isset($args[0])) {
            return;
        }

        $codebase = $event->getCodebase();
        $messageInfo = self::findActiveMessage($event, $codebase);

        if ($messageInfo === null) {
            return;
        }

        [$messageClass, $expectedReplyClass] = $messageInfo;

        $replyArgType = $event->getStatementsSource()->getNodeTypeProvider()->getType($args[0]->value);

        if ($replyArgType === null) {
            return;
        }

        if (self::isAcceptableReply($replyArgType, $expectedReplyClass, $codebase)) {
            return;
        }

        $actualReply = self::renderTypeForMessage($replyArgType);

        IssueBuffer::accepts(
            new MismatchedReplyType(
                $messageClass,
                $expectedReplyClass,
                $actualReply,
                new CodeLocation($event->getStatementsSource(), $args[0]->value),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }

    /**
     * Walk vars_in_scope for variables narrowed to a single concrete class
     * carrying `#[ReplyType]`. Returns [messageClass, expectedReplyClass]
     * if exactly one qualifies; null otherwise.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function findActiveMessage(AfterMethodCallAnalysisEvent $event, Codebase $codebase): ?array
    {
        $context = $event->getContext();
        $candidates = [];

        foreach ($context->vars_in_scope as $type) {
            $atomics = $type->getAtomicTypes();

            if (count($atomics) !== 1) {
                continue;
            }

            $atomic = $type->getSingleAtomic();

            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $messageClass = $atomic->value;

            if (!$codebase->classlikes->classExists($messageClass)) {
                continue;
            }

            $expectedReplyClass = self::extractReplyType($messageClass, $codebase);

            if ($expectedReplyClass === null) {
                continue;
            }

            $candidates[$messageClass] = $expectedReplyClass;
        }

        if (count($candidates) !== 1) {
            return null;
        }

        $messageClass = array_key_first($candidates);

        return [$messageClass, $candidates[$messageClass]];
    }

    private static function extractReplyType(string $messageClass, Codebase $codebase): ?string
    {
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

    private static function isAcceptableReply(Union $replyArgType, string $expectedReplyClass, Codebase $codebase): bool
    {
        foreach ($replyArgType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                return false;
            }

            $actualClass = $atomic->value;

            if (strcasecmp($actualClass, $expectedReplyClass) === 0) {
                continue;
            }

            if (!self::isSubtypeOf($actualClass, $expectedReplyClass, $codebase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when $childClass is the same as $parentClass or extends/
     * implements it. Avoids relying on Codebase methods that vary across
     * Psalm minor versions — walks parent_classes and class_implements on
     * the child's storage instead.
     */
    private static function isSubtypeOf(string $childClass, string $parentClass, Codebase $codebase): bool
    {
        if (!$codebase->classlikes->classExists($childClass)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($childClass);
        $parentLower = strtolower($parentClass);

        foreach ($storage->parent_classes as $cls) {
            if (strcasecmp($cls, $parentLower) === 0) {
                return true;
            }
        }

        foreach ($storage->class_implements as $cls) {
            if (strcasecmp($cls, $parentLower) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function renderTypeForMessage(Union $type): string
    {
        $names = [];

        foreach ($type->getAtomicTypes() as $atomic) {
            $names[] = $atomic instanceof TNamedObject
                ? $atomic->value
                : $atomic->getId();
        }

        return implode('|', $names);
    }
}
