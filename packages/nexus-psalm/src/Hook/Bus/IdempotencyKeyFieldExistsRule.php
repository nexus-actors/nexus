<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\IdempotencyKeyFieldMissing;
use Override;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

use function strtolower;

/**
 * @internal
 *
 * Enforces panel H11: `#[IdempotencyKey(field: 'x')]` MUST name an
 * existing property of the command class whose type is `string`. The
 * idempotency middleware reads this property to derive the dedup key;
 * a missing or non-string field is a boot-time failure.
 */
final class IdempotencyKeyFieldExistsRule implements AfterClassLikeAnalysisInterface
{
    private const string ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\idempotencykey';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait) {
            return null;
        }

        $attribute = self::firstIdempotencyAttribute($storage);

        if ($attribute === null) {
            return null;
        }

        $fieldName = self::extractFieldName($attribute);

        if ($fieldName === null) {
            return null;
        }

        $reason = self::validateField($storage, $fieldName);

        if ($reason === null) {
            return null;
        }

        IssueBuffer::accepts(
            new IdempotencyKeyFieldMissing(
                $storage->name,
                $fieldName,
                $reason,
                $attribute->location,
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    private static function firstIdempotencyAttribute(ClassLikeStorage $storage): ?AttributeStorage
    {
        foreach ($storage->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::ATTRIBUTE) {
                return $attribute;
            }
        }

        return null;
    }

    private static function extractFieldName(AttributeStorage $attribute): ?string
    {
        foreach ($attribute->args as $arg) {
            if ($arg->name !== null && $arg->name !== 'field') {
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

    /**
     * Returns null when the field exists with a string type. Returns a
     * human-readable reason otherwise.
     */
    private static function validateField(ClassLikeStorage $storage, string $fieldName): ?string
    {
        $property = $storage->properties[$fieldName] ?? null;

        if ($property === null) {
            return 'property $' . $fieldName . ' does not exist on the class';
        }

        $type = $property->type ?? $property->signature_type;

        if ($type === null) {
            return 'property $' . $fieldName . ' has no declared type';
        }

        return self::unionIsString($type)
            ? null
            : 'property $' . $fieldName . ' is typed ' . $type->getId() . ', expected string';
    }

    private static function unionIsString(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TString) {
                return false;
            }
        }

        return true;
    }
}
