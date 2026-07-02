<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\PooledConnectionInActorProperty;
use Override;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

use function str_contains;

final class PooledConnectionInActorPropertyRule implements AfterClassLikeAnalysisInterface
{
    private const array ACTOR_INTERFACES = [
        'monadial\nexus\core\actor\actorhandler',
        'monadial\nexus\core\actor\statefulactorhandler',
    ];

    private const array POOLED_TYPES = [
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

        if (!self::implementsActorHandler($storage->class_implements)) {
            return null;
        }

        foreach ($storage->properties as $name => $property) {
            if ($property->is_static) {
                continue;
            }

            $type = $property->type;

            if ($type === null) {
                continue;
            }

            $typeStr = (string) $type;

            if (!self::isPooledType($typeStr)) {
                continue;
            }

            $location = $property->location ?? $property->stmt_location ?? $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new PooledConnectionInActorProperty($storage->name, $name, $typeStr, $location),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }

    /** @param array<lowercase-string, string> $implements */
    private static function implementsActorHandler(array $implements): bool
    {
        foreach (self::ACTOR_INTERFACES as $interface) {
            if (isset($implements[$interface])) {
                return true;
            }
        }

        return false;
    }

    private static function isPooledType(string $typeStr): bool
    {
        foreach (self::POOLED_TYPES as $pooledType) {
            if (str_contains($typeStr, $pooledType)) {
                return true;
            }
        }

        return false;
    }
}
