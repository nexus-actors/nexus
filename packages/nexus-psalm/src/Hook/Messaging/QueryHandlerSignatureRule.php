<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Messaging;

use Monadial\Nexus\Psalm\Issue\InvalidQueryHandlerSignature;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class QueryHandlerSignatureRule implements AfterClassLikeAnalysisInterface
{
    private const string MARKER = 'monadial\nexus\ddd\messaging\handler\queryhandler';
    private const string QUERY = 'monadial\nexus\ddd\messaging\message\query';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->class_implements[self::MARKER])) {
            return null;
        }

        $reason = HandlerSignatureInspector::validateInvoke(
            $event->getCodebase(),
            $storage,
            self::QUERY,
            false,
        );

        if ($reason !== null) {
            $location = $storage->location ?? new CodeLocation($event->getStatementsSource(), $event->getStmt());
            IssueBuffer::accepts(
                new InvalidQueryHandlerSignature($storage->name, $reason, $location),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }
}
