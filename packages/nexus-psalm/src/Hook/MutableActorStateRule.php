<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\MutableActorState;
use Override;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class MutableActorStateRule implements AfterClassLikeAnalysisInterface
{
    private const array ACTOR_INTERFACES = [
        'monadial\nexus\core\actor\actorhandler',
        'monadial\nexus\core\actor\statefulactorhandler',
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

            if ($property->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            if ($property->readonly) {
                continue;
            }

            $location = $property->location ?? $property->stmt_location ?? $storage->location;

            if ($location === null) {
                continue;
            }

            IssueBuffer::accepts(
                new MutableActorState($storage->name, $name, $location),
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
}
