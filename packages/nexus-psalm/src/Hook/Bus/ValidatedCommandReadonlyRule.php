<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\ValidatedCommandNotReadonly;
use Override;
use Psalm\Codebase;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function strtolower;

/**
 * @internal
 *
 * Enforces panel H11: any command class referenced by a `#[Validate]`
 * handler method MUST be `final readonly class`. Validation must observe
 * the same immutable state the handler will receive; a mutable command
 * could be mutated between Validate and Handler stages.
 *
 * Scope is methods carrying the `#[Validate]` attribute — both
 * `#[Handler]`-shape multi-method services and `CommandHandler`
 * implementations are covered.
 */
final class ValidatedCommandReadonlyRule implements AfterClassLikeAnalysisInterface
{
    private const string VALIDATE_ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\validate';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait) {
            return null;
        }

        $codebase = $event->getCodebase();
        $source = $event->getStatementsSource();
        $suppressed = $source->getSuppressedIssues();

        foreach ($storage->methods as $method) {
            if (!self::hasValidateAttribute($method)) {
                continue;
            }

            $commandClass = self::firstParamObjectFqcn($method);

            if ($commandClass === null) {
                continue;
            }

            if (self::isReadonlyClass($codebase, $commandClass)) {
                continue;
            }

            $location = $method->location ?? $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new ValidatedCommandNotReadonly($commandClass, $storage->name, $location),
                $suppressed,
            );
        }

        return null;
    }

    private static function hasValidateAttribute(MethodStorage $method): bool
    {
        foreach ($method->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::VALIDATE_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }

    private static function firstParamObjectFqcn(MethodStorage $method): ?string
    {
        if ($method->params === []) {
            return null;
        }

        $type = $method->params[0]->signature_type ?? $method->params[0]->type;

        if ($type === null) {
            return null;
        }

        return self::firstNamedObjectFqcn($type);
    }

    private static function firstNamedObjectFqcn(Union $type): ?string
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject || $atomic instanceof TNamedObject) {
                return $atomic->value;
            }
        }

        return null;
    }

    private static function isReadonlyClass(Codebase $codebase, string $className): bool
    {
        $key = strtolower($className);

        if (!$codebase->classlike_storage_provider->has($key)) {
            return true; // unknown class — out of scope for this rule
        }

        return $codebase->classlike_storage_provider->get($key)->readonly;
    }
}
