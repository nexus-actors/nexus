<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\AuthorizeBeforeStageUnknown;
use Override;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;

use function in_array;
use function strtolower;

/**
 * @internal
 *
 * Enforces panel H11: any `#[Authorize(before: '...')]` argument MUST
 * name a `PipelineStage` case value. Plan H11 Rule 7
 * (MiddlewareOrderingRule) is merged here — same concern: a string that
 * must match a `PipelineStage` value. The valid stage names are kept in
 * sync with `Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage` (14 cases).
 */
final class AuthorizeBeforeValidationRule implements AfterClassLikeAnalysisInterface
{
    private const string ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\authorize';

    /**
     * Canonical 14-stage pipeline. Hard-coded here because Psalm-time
     * codebase lookup of `PipelineStage::cases()` requires runtime
     * reflection on a parsed enum that may not yet be analyzed.
     *
     */
    private const array VALID_STAGES = [
        'causation',
        'otel-span',
        'logging-start',
        'metrics-start',
        'validation',
        'authorization',
        'idempotency-reserve',
        'occ-retry',
        'handler',
        'idempotency-commit',
        'event-drain',
        'metrics-end',
        'logging-end',
        'span-close',
    ];

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait) {
            return null;
        }

        $source = $event->getStatementsSource();
        $suppressed = $source->getSuppressedIssues();

        foreach ($storage->methods as $methodName => $method) {
            $attribute = self::firstAuthorizeAttribute($method);

            if ($attribute === null) {
                continue;
            }

            $stage = self::extractBeforeStage($attribute);

            if ($stage === null) {
                continue;
            }

            if (in_array($stage, self::VALID_STAGES, true)) {
                continue;
            }

            IssueBuffer::accepts(
                new AuthorizeBeforeStageUnknown(
                    $storage->name,
                    $methodName,
                    $stage,
                    self::VALID_STAGES,
                    $attribute->location,
                ),
                $suppressed,
            );
        }

        return null;
    }

    private static function firstAuthorizeAttribute(MethodStorage $method): ?AttributeStorage
    {
        foreach ($method->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::ATTRIBUTE) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Reads the `before:` named argument. `#[Authorize]` constructor is
     * `(string $policy, ?string $subject = null, ?string $before = null)`
     * — so positional arg 2 (0-indexed) is also `before`.
     */
    private static function extractBeforeStage(AttributeStorage $attribute): ?string
    {
        foreach ($attribute->args as $index => $arg) {
            $isBefore = $arg->name === 'before' || ($arg->name === null && $index === 2);

            if (!$isBefore) {
                continue;
            }

            $type = $arg->type;

            if (!$type instanceof Union) {
                return null;
            }

            foreach ($type->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TLiteralString) {
                    return $atomic->value;
                }
            }

            return null;
        }

        return null;
    }
}
