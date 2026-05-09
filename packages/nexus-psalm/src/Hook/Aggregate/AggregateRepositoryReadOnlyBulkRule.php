<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Aggregate;

use Monadial\Nexus\Psalm\Issue\AggregateRepositoryReadOnlyBulk;
use Override;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function strtolower;

/**
 * @internal
 *
 * Enforces v6 §9.1: bulk methods on `AggregateRepository` subclasses
 * must be marked `#[BulkCommand(...)]`. The repository's default shape
 * is `find()` / `save()` — adding an `inBatch(BatchId): iterable<Order>`
 * is tolerated only when a command handler legitimately needs to mutate
 * every aggregate in a set. Read-only collection queries are queries in
 * disguise; route them through `QueryBus` + projection tables instead.
 *
 * Detection: any concrete class implementing `AggregateRepository`. Any
 * non-attributed public method whose return type is iterable
 * (array<…>, iterable<…>, Iterator<…>, Generator<…>, …) emits
 * `AggregateRepositoryReadOnlyBulk`.
 */
final class AggregateRepositoryReadOnlyBulkRule implements AfterClassLikeAnalysisInterface
{
    private const string REPOSITORY_INTERFACE = 'monadial\nexus\ddd\aggregate\repository\aggregaterepository';

    private const string BULK_COMMAND_ATTRIBUTE = 'monadial\nexus\ddd\aggregate\repository\attribute\bulkcommand';

    private const array ITERABLE_OBJECT_FQCNS = [
        'generator',
        'iterator',
        'iteratoraggregate',
        'traversable',
    ];

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->class_implements[self::REPOSITORY_INTERFACE])) {
            return null;
        }

        $source = $event->getStatementsSource();
        $suppressed = $source->getSuppressedIssues();

        foreach ($storage->methods as $methodName => $method) {
            if (!self::isPublicNonStatic($method)) {
                continue;
            }

            if ($methodName === '__construct' || $methodName === 'find' || $methodName === 'save') {
                continue;
            }

            if (self::hasBulkCommandAttribute($method)) {
                continue;
            }

            if (!self::returnsIterable($method)) {
                continue;
            }

            $location = $method->location ?? $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new AggregateRepositoryReadOnlyBulk($storage->name, $methodName, $location),
                $suppressed,
            );
        }

        return null;
    }

    private static function isPublicNonStatic(MethodStorage $method): bool
    {
        return $method->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC && !$method->is_static;
    }

    private static function hasBulkCommandAttribute(MethodStorage $method): bool
    {
        foreach ($method->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::BULK_COMMAND_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }

    private static function returnsIterable(MethodStorage $method): bool
    {
        $returnType = $method->return_type ?? $method->signature_return_type;

        if ($returnType === null) {
            return false;
        }

        return self::unionIsIterable($returnType);
    }

    private static function unionIsIterable(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TArray || $atomic instanceof TKeyedArray || $atomic instanceof TIterable) {
                return true;
            }

            if ($atomic instanceof TGenericObject || $atomic instanceof TNamedObject) {
                $name = strtolower($atomic->value);

                foreach (self::ITERABLE_OBJECT_FQCNS as $iterableFqcn) {
                    if ($name === $iterableFqcn) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
