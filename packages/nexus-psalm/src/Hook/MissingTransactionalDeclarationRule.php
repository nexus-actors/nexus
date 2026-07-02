<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\MissingTransactionalDeclaration;
use Override;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\MethodStorage;

use function str_contains;
use function strtolower;

final class MissingTransactionalDeclarationRule implements AfterClassLikeAnalysisInterface
{
    private const string TRANSACTIONAL_ATTRIBUTE = 'Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional';

    private const array TRANSACTIONAL_PARAM_TYPES = [
        'Doctrine\DBAL\Connection',
        'Doctrine\ORM\EntityManagerInterface',
    ];

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait) {
            return null;
        }

        if (!self::hasTransactionalAttribute($storage->attributes, $storage->methods)) {
            return null;
        }

        if (self::hasTransactionalParamInAnyMethod($storage->methods)) {
            return null;
        }

        $location = $storage->location;

        if ($location === null) {
            return null;
        }

        IssueBuffer::accepts(
            new MissingTransactionalDeclaration($storage->name, $location),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * @param list<AttributeStorage> $classAttributes
     * @param array<lowercase-string, MethodStorage> $methods
     */
    private static function hasTransactionalAttribute(array $classAttributes, array $methods): bool
    {
        foreach ($classAttributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === strtolower(self::TRANSACTIONAL_ATTRIBUTE)) {
                return true;
            }
        }

        foreach ($methods as $method) {
            foreach ($method->attributes as $attribute) {
                if (strtolower($attribute->fq_class_name) === strtolower(self::TRANSACTIONAL_ATTRIBUTE)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<lowercase-string, MethodStorage> $methods */
    private static function hasTransactionalParamInAnyMethod(array $methods): bool
    {
        foreach ($methods as $method) {
            foreach ($method->params as $param) {
                if ($param->type === null) {
                    continue;
                }

                $typeStr = (string) $param->type;

                if (self::isTransactionalType($typeStr)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isTransactionalType(string $typeStr): bool
    {
        foreach (self::TRANSACTIONAL_PARAM_TYPES as $type) {
            if (str_contains($typeStr, $type)) {
                return true;
            }
        }

        return false;
    }
}
