<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\CommandHandlerNonVoidReturn;
use Override;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TVoid;

use function strtolower;

/**
 * @internal
 *
 * Enforces panel H11: any method marked `#[Handler]` (the multi-method
 * service shortcut for `CommandHandler`) MUST declare `: void` — commands
 * are pure CQS and the post-handler outcome flows out via events.
 *
 * Scope is `#[Handler]`-attributed methods. The
 * `CommandHandlerSignatureRule` already covers the
 * `implements CommandHandler` path; this rule fills the multi-method
 * service gap.
 */
final class CommandHandlerReturnTypeRule implements AfterClassLikeAnalysisInterface
{
    private const string HANDLER_ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\handler';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait) {
            return null;
        }

        foreach ($storage->methods as $methodName => $method) {
            if (!self::hasHandlerAttribute($method)) {
                continue;
            }

            $returnDescription = self::returnTypeDescription($method);

            if ($returnDescription === null) {
                continue;
            }

            $location = $method->location ?? $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new CommandHandlerNonVoidReturn(
                    $storage->name,
                    $methodName,
                    $returnDescription,
                    $location,
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }

    private static function hasHandlerAttribute(MethodStorage $method): bool
    {
        foreach ($method->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::HANDLER_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns null when the return type is exactly `void` (clean).
     * Returns the offending return-type id otherwise — for the error
     * message. Missing return type counts as a violation; `: void` is
     * mandatory.
     */
    private static function returnTypeDescription(MethodStorage $method): ?string
    {
        $returnType = $method->signature_return_type ?? $method->return_type;

        if ($returnType === null) {
            return '(no explicit return type)';
        }

        $atomicTypes = $returnType->getAtomicTypes();

        foreach ($atomicTypes as $atomic) {
            if (!$atomic instanceof TVoid) {
                return $returnType->getId();
            }
        }

        return null;
    }
}
