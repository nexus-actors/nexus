<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Messaging;

use Monadial\Nexus\Psalm\Issue\NonReadonlyMessageBody;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class ReadonlyMessageBodyRule implements AfterClassLikeAnalysisInterface
{
    private const array MARKER_INTERFACES = [
        'monadial\nexus\ddd\messaging\message\command',
        'monadial\nexus\ddd\messaging\message\query',
    ];

    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->abstract) {
            return null;
        }

        $marker = self::firstMatchingMarker($storage->class_implements);

        if ($marker === null) {
            return null;
        }

        if ($storage->final && $storage->readonly) {
            return null;
        }

        $location = $storage->location ?? new CodeLocation($event->getStatementsSource(), $event->getStmt());

        IssueBuffer::accepts(
            new NonReadonlyMessageBody($storage->name, $marker, $location),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * @param array<string, string> $implements
     */
    private static function firstMatchingMarker(array $implements): ?string
    {
        foreach ($implements as $lower => $original) {
            if (in_array($lower, self::MARKER_INTERFACES, true)) {
                return $original;
            }
        }

        return null;
    }
}
